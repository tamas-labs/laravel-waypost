<?php

declare(strict_types=1);

use Waypost\Enums\ChainFlag;
use Waypost\Reports\Labels;
use Waypost\Reports\LocalFormat;

it('has a Hungarian text for every flag of a scan, the app\'s and the server\'s', function (): void {
    $scan = schema('chain/payload-scan');
    /** @var list<string> $appFlags */
    $appFlags = $scan['properties']['flags']['items']['enum'];
    $flags = [...$appFlags, ...array_map(fn (ChainFlag $flag): string => $flag->value, ChainFlag::cases())];

    expect(array_keys(Labels::FLAGS))->toEqualCanonicalizing($flags);
});

it('writes dates and periods the Hungarian way', function (): void {
    expect(LocalFormat::date('2026-11-03'))->toBe('2026. november 3.')
        ->and(LocalFormat::dateWithWeekday('2026-11-03'))->toBe('2026. november 3., kedd')
        ->and(LocalFormat::dateRange('2026-10-26', '2026-10-27'))->toBe('2026. október 26–27.')
        ->and(LocalFormat::dateRange('2026-10-31', '2026-11-01'))->toBe('2026. október 31. – november 1.')
        ->and(LocalFormat::dateRange('2026-12-28', '2027-01-03'))->toBe('2026. december 28. – 2027. január 3.')
        ->and(LocalFormat::month('2026-10-01'))->toBe('2026. október')
        ->and(LocalFormat::days('2026-10-30', '2026-11-02'))->toBe(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'])
        ->and((new LocalFormat('Europe/Budapest'))->time(new DateTimeImmutable('2026-10-25T00:30:00Z')))->toBe('02:30')
        ->and((new LocalFormat('Europe/Budapest'))->time(new DateTimeImmutable('2026-10-25T01:30:00Z')))->toBe('02:30');
});
