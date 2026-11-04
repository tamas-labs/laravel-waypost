<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waypost\Auth\Caller;
use Waypost\Enums\ReportFrequency;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Http\SignedUrls;
use Waypost\Models\Report;
use Waypost\Support\Time;
use Waypost\Support\Uuid;

/**
 * GET /reports (spec: API-szerződés: jelentésarchívum): the reports of the supervisor's sites, newest period first,
 * a page at a time; the stored file behind a signed URL (GET /reports/{id}/file), which needs no token.
 */
final class ReportController extends Controller
{
    public const int PAGE_SIZE = 100;

    private const string CURSOR = '/^(?<time>[^_]+)_(?<id>[0-9a-f-]{36})$/D';

    /** @return array{reports: list<array<string, mixed>>, next_cursor: string|null} */
    public function index(Request $request, Caller $caller): array
    {
        $type = $request->query('type');
        if ($type !== null && (!is_string($type) || ReportFrequency::tryFrom($type) === null)) {
            self::invalid('type', 'Ismeretlen jelentéstípus.');
        }
        $after = self::cursor($request);

        $reports = Report::query()
            ->whereIn('site_id', self::visibleSites($request, $caller))
            ->when($type, fn (Builder $reports, string $type) => $reports->where('type', $type))
            ->when($after, fn (Builder $reports, array $after) => $reports->where(fn (Builder $older) => $older
                ->where('period_start', '<', $after[0])
                ->orWhere(fn (Builder $same) => $same->where('period_start', $after[0])->where('id', '<', $after[1]))))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $page = $reports->take(self::PAGE_SIZE);
        $last = $page->last();

        return [
            'reports' => array_values($page->map(Entities::report(...))->all()),
            'next_cursor' => $reports->count() > self::PAGE_SIZE && $last !== null ? self::encode($last) : null,
        ];
    }

    /** The stored file, by a signed URL of GET /reports. */
    public function file(Request $request, string $report): StreamedResponse
    {
        SignedUrls::assertValid($request);
        $record = Report::query()->find($report);
        $disk = Storage::disk(config('waypost.storage.disk'));
        if ($record?->path === null || !$disk->exists($record->path)) {
            throw ApiException::notFound();
        }

        return $disk->response($record->path, basename($record->path), ['Cache-Control' => 'private, no-store']);
    }

    /**
     * The position after which the page starts: period_start (as database text) and id of the last report sent.
     *
     * @return array{string, string}|null
     */
    private static function cursor(Request $request): ?array
    {
        $text = $request->query('cursor');
        if ($text === null) {
            return null;
        }
        $decoded = is_string($text) ? base64_decode(strtr($text, '-_', '+/'), true) : false;
        if ($decoded === false || preg_match(self::CURSOR, $decoded, $parts) !== 1 || !Uuid::isV7($parts['id'])) {
            self::invalid('cursor', 'Hibás kurzor; kurzor nélkül az első oldal kérhető le.');
        }

        try {
            return [Time::fromIso($parts['time'])->format(Time::DATABASE_FORMAT), $parts['id']];
        } catch (InvalidArgumentException) {
            self::invalid('cursor', 'Hibás kurzor; kurzor nélkül az első oldal kérhető le.');
        }
    }

    private static function encode(Report $report): string
    {
        return rtrim(strtr(base64_encode(Time::iso($report->period_start) . "_{$report->id}"), '+/', '-_'), '=');
    }
}
