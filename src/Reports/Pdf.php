<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use LogicException;

/**
 * Renders a report's HTML (resources/views/pdf) to an A4 PDF with dompdf, with the package's own options rather than
 * the host application's: nothing is fetched from the network or the disk (the photos are data URIs), no PHP or
 * JavaScript runs, and the default font is DejaVu Sans, which has the Hungarian ő and ű.
 */
final class Pdf
{
    public const string FONT = 'DejaVu Sans';

    private function __construct()
    {
    }

    /**
     * At least this much memory while dompdf renders: it keeps the whole layout in memory, about 0.1 MB a table row
     * (a monthly report of a busy site has some hundred), more than a queue worker's usual 128M leaves.
     */
    public const string MEMORY_LIMIT = '512M';

    /** @param  string  $footer  left of the page number on every page */
    public static function render(string $html, string $footer): string
    {
        $limit = (string) ini_get('memory_limit');
        $raise = $limit !== '-1' && ini_parse_quantity($limit) < ini_parse_quantity(self::MEMORY_LIMIT);
        if ($raise) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }

        try {
            return self::output($html, $footer);
        } finally {
            if ($raise) {
                // dompdf's frames point at each other: only the cycle collector frees them. Should the memory in use
                // still exceed the old limit, the raised one stays.
                gc_collect_cycles();
                gc_mem_caches();
                @ini_set('memory_limit', $limit);
            }
        }
    }

    private static function output(string $html, string $footer): string
    {
        $dompdf = new Dompdf(new Options([
            'defaultFont' => self::FONT,
            'defaultPaperSize' => 'a4',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'tempDir' => sys_get_temp_dir(),
        ]));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont(self::FONT) ?? throw new LogicException('dompdf has no ' . self::FONT . '.');
        $canvas->page_text(40, $canvas->get_height() - 32, $footer . ' · {PAGE_NUM}/{PAGE_COUNT}', $font, 7.5, [0.31, 0.36, 0.45]);

        return (string) $dompdf->output();
    }
}
