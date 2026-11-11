<?php

declare(strict_types=1);

namespace Waypost\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\URL;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Media;
use Waypost\Models\Report;
use Waypost\Models\Site;
use Waypost\Reports\Retention;
use Waypost\Support\Time;

/**
 * Signed, expiring URLs of the private files (spec: Fájlok: letöltés aláírt, lejáró URL-lel). They need no token, so
 * a link in an e-mail opens in a browser; the signature covers the record's id and the expiry, so neither can be
 * changed. A photo's link lives until the data retention deletes the photo, a report file's (GET /reports) for
 * waypost.signed_urls.report_ttl_min minutes. The link alone grants access, so in production it is always https
 * (entities/report.json: download.url), whatever the scheme of APP_URL or of the request.
 */
final class SignedUrls
{
    public const string PHOTO_ROUTE = 'waypost.media.file';

    public const string REPORT_ROUTE = 'waypost.reports.file';

    private function __construct()
    {
    }

    /**
     * The link of an uploaded photo of the site, null if it is not uploaded or the retention has already deleted it.
     *
     * @param  int|null  $retentionDays  Retention::days() of the site, if already counted
     */
    public static function photo(Media $media, Site $site, ?int $retentionDays = null): ?string
    {
        $expires = Retention::until($site, $media->taken_at, $retentionDays);
        if ($media->uploaded_at === null || !$expires->isFuture()) {
            return null;
        }

        return self::urls()->temporarySignedRoute(self::PHOTO_ROUTE, $expires, ['media' => $media->id]);
    }

    /**
     * The link of a report's stored file (the PDF), null without one.
     *
     * @return array{url: string, expires_at: string}|null  entities/report.json: download
     */
    public static function report(Report $report): ?array
    {
        if ($report->path === null) {
            return null;
        }
        $expires = Time::now()->addMinutes((int) config('waypost.signed_urls.report_ttl_min'))->startOfSecond();

        return [
            'url' => self::urls()->temporarySignedRoute(self::REPORT_ROUTE, $expires, ['report' => $report->id]),
            'expires_at' => Time::iso($expires),
        ];
    }

    /** A request to a signed route: an expired or changed link is forbidden. */
    public static function assertValid(Request $request): void
    {
        if (!$request->hasValidSignature()) {
            throw ApiException::forbidden('A link lejárt vagy érvénytelen.');
        }
    }

    /**
     * The URL generator of the links: the application's, in production a copy of it that makes https links. A copy,
     * so that the host's own URLs keep their scheme. Behind a proxy that ends TLS the host has to trust the proxy
     * (TrustProxies), or the request is http, its URL is not the signed one, and the link is forbidden.
     */
    private static function urls(): UrlGenerator
    {
        $urls = URL::getFacadeRoot();
        assert($urls instanceof UrlGenerator);
        if (!app()->isProduction()) {
            return $urls;
        }
        $https = clone $urls;
        // A fresh route generator and scheme cache: the copied ones belong to the application's generator.
        $https->setRequest($urls->getRequest());
        $https->forceScheme('https');

        return $https;
    }

}
