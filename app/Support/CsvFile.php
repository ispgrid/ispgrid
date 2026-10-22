<?php

namespace App\Support;

use App\Actions\Import\RowError;
use RuntimeException;

/**
 * An import file read into rows keyed by column name (Plan 9 spec, "File format"). Rows are numbered like the
 * spreadsheet: the header is row 1, so the first data row is row 2. Blank rows are skipped.
 */
final class CsvFile
{
    public const MAX_ROWS = 5000;

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @param  list<RowError>  $errors
     */
    public function __construct(public readonly array $rows, public readonly array $errors) {}

    /**
     * @param  list<string>  $columns  every column the file may have, lower case
     * @param  list<string>  $required  the columns the header must have
     */
    public static function read(string $path, array $columns, array $required): self
    {
        $content = (string) file_get_contents($path);

        if (! mb_check_encoding($content, 'UTF-8')) {
            return self::failed(__('The file is not UTF-8 text. In your spreadsheet, save it as "CSV UTF-8".'));
        }

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV file.');
        }

        fwrite($stream, (string) preg_replace('/^\xEF\xBB\xBF/', '', $content));
        rewind($stream);

        try {
            return self::parse($stream, $columns, $required);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $columns
     * @param  list<string>  $required
     */
    private static function parse($stream, array $columns, array $required): self
    {
        $header = fgetcsv($stream, escape: '');

        if ($header === false || $header === [null]) {
            return self::failed(__('The file has no rows.'));
        }

        $header = array_map(fn (?string $name): string => strtolower(trim((string) $name)), $header);

        // Excel adds empty unnamed columns at the end when cells to the right were ever touched.
        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }

        $errors = self::headerErrors($header, $columns, $required);

        if ($errors !== []) {
            return new self([], $errors);
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($stream, escape: '')) !== false) {
            $line++;
            $cells = array_map(fn (?string $cell): string => trim((string) $cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            // Their cells stay empty too; a value there still counts as a stray comma below.
            while (count($cells) > count($header) && end($cells) === '') {
                array_pop($cells);
            }

            if (count($cells) > count($header)) {
                $errors[] = new RowError($line, null, __('This row has :cells cells but the header has :columns. Check for a stray comma.', [
                    'cells' => count($cells),
                    'columns' => count($header),
                ]));

                continue;
            }

            if (count($cells) < count($header)) {
                $errors[] = new RowError($line, null, __('This row has :cells cells but the header has :columns. Check for a missing comma.', [
                    'cells' => count($cells),
                    'columns' => count($header),
                ]));

                continue;
            }

            $values = array_fill_keys($columns, '');

            foreach ($header as $index => $name) {
                $values[$name] = $cells[$index];
            }

            $rows[] = ['line' => $line, 'values' => $values];
        }

        if (count($rows) + count($errors) > self::MAX_ROWS) {
            return self::failed(__('The file has more than :max rows. Split it into smaller files.', ['max' => number_format(self::MAX_ROWS)]));
        }

        if ($rows === [] && $errors === []) {
            return self::failed(__('The file has no rows.'));
        }

        return new self($rows, $errors);
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $columns
     * @param  list<string>  $required
     * @return list<RowError>
     */
    private static function headerErrors(array $header, array $columns, array $required): array
    {
        $errors = [];

        foreach ($header as $index => $name) {
            if (! in_array($name, $columns, true)) {
                $errors[] = new RowError(null, null, $name === ''
                    ? __('Column :number has no name.', ['number' => $index + 1])
                    : __('Unknown column: :name.', ['name' => $name]));
            }
        }

        foreach (array_unique(array_diff_assoc($header, array_unique($header))) as $name) {
            $errors[] = new RowError(null, null, __('The column :name is there twice.', ['name' => $name]));
        }

        foreach (array_diff($required, $header) as $name) {
            $errors[] = new RowError(null, null, __('Missing column: :name.', ['name' => $name]));
        }

        return $errors;
    }

    /**
     * The problem of each cell that is not plain English text, keyed by column. Imports take English letters, digits
     * and symbols only. Excel's plain "CSV" can't hold Bengali or Devanagari letters and saves each one as "?", which
     * is English, so the text columns are also checked for two in a row; a single "?" may be meant.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $textColumns
     * @return array<string, string>
     */
    public static function textErrors(array $values, array $textColumns): array
    {
        $errors = [];

        foreach ($values as $column => $value) {
            if (! RadiusText::isEnglish($value)) {
                $errors[$column] = __('Use English letters, digits and symbols only.');
            } elseif (in_array($column, $textColumns, true) && str_contains($value, '??')) {
                $errors[$column] = __('Excel turned letters it could not save into ?. Type this in English letters.');
            }
        }

        return $errors;
    }

    private static function failed(string $message): self
    {
        return new self([], [new RowError(null, null, $message)]);
    }
}
