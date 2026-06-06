<?php
declare(strict_types=1);

namespace Sportlauf\Services;

final class PdfService
{
    public static function output(string $html, string $filename, string $orientation = 'portrait'): void
    {
        if (class_exists('\\Dompdf\\Dompdf')) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', $orientation);
            $dompdf->render();
            $dompdf->stream($filename, ['Attachment' => false]);
            return;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
    }
}
