<?php

namespace App\Actions\Import;

/**
 * One problem in an import file. A null row is about the whole file; a null column is about the whole row.
 */
final readonly class RowError
{
    public function __construct(public ?int $row, public ?string $column, public string $message) {}

    /**
     * @return array{row: ?int, column: ?string, message: string}
     */
    public function toArray(): array
    {
        return ['row' => $this->row, 'column' => $this->column, 'message' => $this->message];
    }
}
