<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV download for a spreadsheet. A text cell that a spreadsheet would run as a formula (=, +, -, @, tab, CR at
 * the start: a customer named "=HYPERLINK(...)") gets a leading apostrophe; numbers stay numbers.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function download(string $fileName, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($output, array_map(self::cell(...), $row), escape: '');
            }

            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function cell(string|int|float|null $value): string
    {
        $text = (string) $value;

        return $text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
