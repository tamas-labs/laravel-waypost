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

    /** @param  string  $footer  left of the page number on every page */
    public static function render(string $html, string $footer): string
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
