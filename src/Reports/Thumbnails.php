<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Illuminate\Support\Facades\Storage;
use Waypost\Models\Media;

/**
 * The photos of a PDF report, embedded as small JPEG data URIs (spec: a PDF a fotókat beágyazott bélyegképként
 * tartalmazza), so the PDF stays evidence after the retention deletes the files, and does not grow by megabytes.
 */
final class Thumbnails
{
    /** The longer side, in pixels. */
    public const int SIZE = 480;

    public const int QUALITY = 75;

    /**
     * The most pixels a photo may have to be scaled. GD unpacks an image to some 4–5 bytes a pixel whatever its file
     * size, so a small JPEG with a 20 000 × 20 000 header would ask for gigabytes and kill the report's job, at
     * every attempt. This keeps it near 60 MB, under a queue worker's usual 128M; the app sends 1600 px, a 12 MP
     * camera image still fits.
     */
    public const int MAX_PIXELS = 4096 * 3072;

    private function __construct()
    {
    }

    /**
     * The uploaded photo's thumbnail, null if it is not on the disk (any more), is not an image GD can read, or is
     * larger than MAX_PIXELS (by its header, before anything is unpacked).
     */
    public static function dataUri(Media $media): ?string
    {
        $disk = Storage::disk(config('waypost.storage.disk'));
        if ($media->path === null || !$disk->exists($media->path)) {
            return null;
        }
        $bytes = (string) $disk->get($media->path);
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::SIZE / max($width, $height));
        $thumbnail = $scale < 1.0 ? imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) : $image;
        if ($thumbnail === false) {
            return null;
        }

        ob_start();
        imagejpeg($thumbnail, null, self::QUALITY);

        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }
}
