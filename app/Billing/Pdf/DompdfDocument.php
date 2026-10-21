<?php

namespace App\Billing\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * HTML to PDF with dompdf, set up the same way for every billing document: A4, DejaVu Sans (it has the rupee sign
 * and the Latin letters), no remote files (images come in as data URIs), files only from inside the app, and
 * "Page N of M" at the bottom.
 */
final class DompdfDocument
{
    public static function render(string $html): string
    {
        $temp = storage_path('app/dompdf');
        File::ensureDirectoryExists($temp);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setChroot(base_path());
        $options->setTempDir($temp);
        $options->setFontCache($temp);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();

        // dompdf leaves the CSS pages counter at 0; the canvas knows the page count once the document is laid out.
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() / 2 - 28, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.32, 0.38, 0.43]);

        return (string) $dompdf->output();
    }
}
