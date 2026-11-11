<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Waypost\Enums\ErrorCode;
use Waypost\Models\Device;
use Waypost\Models\DeviceDiagnostic;
use Waypost\Support\Time;
use Waypost\Support\Uuid;

pest()->use(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $fields
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function reportDiagnostic(Device $device, array $fields = [], ?string $token = null): TestResponse
{
    return requestApi('POST', 'devices/diagnostics', ['Authorization' => 'Bearer ' . ($token ?? deviceToken($device))], [
        'id' => Uuid::v7(),
        'device_id' => $device->id,
        'code' => 'chain_gap',
        'entry_id' => Uuid::v7(),
        'seq' => 12,
        'expected_seq' => 10,
        'server_last_seq' => 9,
        'rejected_count' => 3,
        'occurred_at' => '2026-10-27T10:05:12.345Z',
        ...$fields,
    ]);
}

it('stores what the device reports', function (): void {
    $device = Device::factory()->create();

    $response = reportDiagnostic($device)->assertOk();

    expect($response)->toMatchSchema('api/device-diagnostic.response');
    $stored = DeviceDiagnostic::query()->sole();
    expect($stored->device_id)->toBe($device->id)
        ->and($stored->code)->toBe(ErrorCode::ChainGap)
        ->and($stored->seq)->toBe(12)
        ->and($stored->expected_seq)->toBe(10)
        ->and($stored->server_last_seq)->toBe(9)
        ->and($stored->rejected_count)->toBe(3)
        ->and(Time::iso($stored->occurred_at))->toBe('2026-10-27T10:05:12.345Z')
        ->and($stored->received_at)->not->toBeNull();
});

it('stores a refused upload (validation_failed), which has no expected_seq', function (): void {
    $device = Device::factory()->create();

    reportDiagnostic($device, ['code' => 'validation_failed', 'expected_seq' => null, 'rejected_count' => 1])->assertOk();

    expect(DeviceDiagnostic::query()->sole()->code)->toBe(ErrorCode::ValidationFailed);
    reportDiagnostic($device, ['code' => 'validation_failed', 'expected_seq' => 11])->assertUnprocessable();
});

it('changes nothing when the same report comes again, even with a new Idempotency-Key', function (): void {
    $device = Device::factory()->create();
    $id = Uuid::v7();

    reportDiagnostic($device, ['id' => $id, 'seq' => 12])->assertOk();
    reportDiagnostic($device, ['id' => $id, 'seq' => 99])->assertOk();

    expect(DeviceDiagnostic::query()->sole()->seq)->toBe(12);
});

it('does not take a report for another device', function (): void {
    $device = Device::factory()->create();

    reportDiagnostic($device, ['device_id' => Device::factory()->create()->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(DeviceDiagnostic::query()->count())->toBe(0);
});

it('does not take a code that is not of the chain', function (): void {
    reportDiagnostic(Device::factory()->create(), ['code' => 'forbidden'])->assertStatus(422);

    expect(DeviceDiagnostic::query()->count())->toBe(0);
});

it('takes the report of a revoked device until it closes its upload', function (): void {
    $device = Device::factory()->revoked()->create();
    $token = deviceToken($device);

    reportDiagnostic($device, token: $token)->assertOk();

    $device->update(['upload_closed_at' => Time::now()]);
    reportDiagnostic($device, token: $token)->assertStatus(401)->assertJsonPath('error.code', 'device_revoked');

    expect(DeviceDiagnostic::query()->count())->toBe(1);
});

it('wants a device token', function (): void {
    requestApi('POST', 'devices/diagnostics', [], ['id' => Uuid::v7()])->assertStatus(401);
});
