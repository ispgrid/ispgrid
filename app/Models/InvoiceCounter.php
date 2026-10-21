<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The next invoice number of one series ('all', or a year such as '2026-27' when numbering restarts yearly).
 */
#[Fillable(['series', 'next_number'])]
class InvoiceCounter extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
        ];
    }
}
