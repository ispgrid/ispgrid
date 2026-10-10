<?php

namespace App\Actions\Import;

use App\Exceptions\ImportFailed;

/**
 * One kind of CSV import (Plan 9 spec): Check lists every problem without writing; Import checks again inside
 * one transaction and writes every row or none.
 */
interface Importer
{
    /**
     * @return list<string>
     */
    public function columns(): array;

    /**
     * @return list<string>
     */
    public function requiredColumns(): array;

    /**
     * Every column, in template order, with the template's example value.
     *
     * @return array<string, string>
     */
    public function example(): array;

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return list<RowError>
     */
    public function check(array $rows): array;

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return int the number of rows written
     *
     * @throws ImportFailed when any row has a problem; nothing is written
     */
    public function import(array $rows): int;
}
