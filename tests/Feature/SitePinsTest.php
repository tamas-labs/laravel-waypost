<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Waypost\Enums\Role;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Pin\PinHasher;
use Waypost\Pin\SitePins;

pest()->use(RefreshDatabase::class);

/** A user with the PIN, assigned to the sites. */
function pinHolder(string $pin, Site ...$sites): User
{
    $user = User::factory()->withPin($pin)->create();
    $user->sites()->attach(array_map(fn (Site $site) => $site->id, $sites));

    return $user;
}

it('finds a PIN taken by a guard or a supervisor of the site', function (): void {
    $site = Site::factory()->create();
    pinHolder('111111', $site);
    $supervisor = pinHolder('222222', $site);
    $supervisor->update(['role' => Role::Supervisor]);

    $pins = app(SitePins::class);

    expect($pins->isTaken('111111', [$site->id]))->toBeTrue()
        ->and($pins->isTaken('222222', [$site->id]))->toBeTrue()
        ->and($pins->isTaken('333333', [$site->id]))->toBeFalse();
});

it('ignores other sites, users without PIN, deleted users and the user being changed', function (): void {
    [$site, $other] = Site::factory()->count(2)->create()->all();
    pinHolder('111111', $other);
    User::factory()->create()->sites()->attach($site);
    pinHolder('222222', $site)->delete();
    $self = pinHolder('333333', $site);

    $pins = app(SitePins::class);

    expect($pins->isTaken('111111', [$site->id]))->toBeFalse()
        ->and($pins->isTaken('111111', [$site->id, $other->id]))->toBeTrue()
        ->and($pins->isTaken('222222', [$site->id]))->toBeFalse()
        ->and($pins->isTaken('333333', [$site->id], except: $self))->toBeFalse()
        ->and($pins->isTaken('333333', [$site->id]))->toBeTrue()
        ->and($pins->isTaken('111111', []))->toBeFalse();
});

it('counts inactive users: they can be reactivated', function (): void {
    $site = Site::factory()->create();
    pinHolder('111111', $site)->update(['active' => false]);

    expect(app(SitePins::class)->isTaken('111111', [$site->id]))->toBeTrue();
});

it('generates a PIN that is free on the sites', function (): void {
    $site = Site::factory()->create();
    // The same seed gives the same sequence: the first candidate is taken, so the second one comes back.
    $sequence = new Randomizer(new Mt19937(2026));
    $first = sprintf('%06d', $sequence->getInt(0, 999_999));
    $second = sprintf('%06d', $sequence->getInt(0, 999_999));
    pinHolder($first, $site);

    $pins = new SitePins(new PinHasher(new Randomizer(new Mt19937(2026))));

    expect($pins->generate([$site->id]))->toBe($second);
});

it('gives up when it finds no free PIN', function (): void {
    $site = Site::factory()->create();
    pinHolder('000000', $site);
    $zeros = new class implements Random\Engine {
        public function generate(): string
        {
            return "\0\0\0\0\0\0\0\0";
        }
    };

    $pins = new SitePins(new PinHasher(new Randomizer($zeros)));

    expect(fn () => $pins->generate([$site->id]))->toThrow(RuntimeException::class, 'No free PIN found in 100 attempts.');
});
