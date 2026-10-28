<?php

declare(strict_types=1);

use Waypost\Enums\ChainEntryType;
use Waypost\Enums\ErrorCode;
use Waypost\Enums\IncidentCategory;
use Waypost\Enums\OrderMode;
use Waypost\Enums\Platform;
use Waypost\Enums\ReportFrequency;
use Waypost\Enums\Role;
use Waypost\Enums\RoundStatus;
use Waypost\Enums\ScanMethod;
use Waypost\Enums\ScheduleMode;
use Waypost\Enums\ShiftClosedBy;
use Waypost\Enums\TagType;

/**
 * The "enum" of a property of a waypost/schema schema.
 *
 * @return list<string>
 */
function schemaEnum(string $schema, string $property): array
{
    /** @var array{properties: array<string, array{enum: list<string>}>} $definition */
    $definition = schema($schema);

    return $definition['properties'][$property]['enum'];
}

/**
 * @param  class-string<BackedEnum>  $enum
 * @return list<int|string>
 */
function enumValues(string $enum): array
{
    return array_map(fn (BackedEnum $case): int|string => $case->value, $enum::cases());
}

it('has the values of the schema', function (string $enum, string $schema, string $property): void {
    /** @var class-string<BackedEnum> $enum */
    expect(enumValues($enum))->toBe(schemaEnum($schema, $property));
})->with([
    'Role' => [Role::class, 'entities/user', 'role'],
    'Platform' => [Platform::class, 'entities/device', 'platform'],
    'TagType' => [TagType::class, 'entities/checkpoint', 'tag_type'],
    'OrderMode' => [OrderMode::class, 'entities/route', 'order_mode'],
    'ScheduleMode' => [ScheduleMode::class, 'entities/schedule', 'mode'],
    'ReportFrequency' => [ReportFrequency::class, 'entities/report-subscription', 'frequency'],
    'ChainEntryType' => [ChainEntryType::class, 'chain/entry', 'type'],
    'ShiftClosedBy' => [ShiftClosedBy::class, 'chain/payload-shift_closed', 'closed_by'],
    'ScanMethod' => [ScanMethod::class, 'chain/payload-scan', 'method'],
    'IncidentCategory' => [IncidentCategory::class, 'chain/payload-incident', 'category'],
]);

it('has every final round status of round_closed', function (): void {
    expect(enumValues(RoundStatus::class))->toContain(...schemaEnum('chain/payload-round_closed', 'status'));
});

it('has the error codes of the schema', function (): void {
    expect(enumValues(ErrorCode::class))->toBe(schema('common/error-code')['enum']);
});

it('gives every request-level error code the status of the specification', function (): void {
    expect(array_map(fn (ErrorCode $code): int => $code->status(), [
        ErrorCode::Unauthenticated, ErrorCode::DeviceRevoked, ErrorCode::Forbidden, ErrorCode::NotFound,
        ErrorCode::ValidationFailed, ErrorCode::Conflict, ErrorCode::RateLimited,
    ]))->toBe([401, 401, 403, 404, 422, 409, 429]);
});
