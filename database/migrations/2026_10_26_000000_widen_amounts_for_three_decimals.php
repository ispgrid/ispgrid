<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money columns get a third decimal for dinars and rials (1000 fils), keeping ten whole digits. Widening keeps
 * every stored value as it is.
 */
return new class extends Migration
{
    /**
     * Each table's money columns: name => default (null = none).
     *
     * @var array<string, array<string, int|null>>
     */
    private const COLUMNS = [
        'plans' => ['price' => 0],
        'recharges' => ['amount' => 0],
        'advance_renewals' => ['amount' => 0],
        'invoices' => ['taxable_total' => null, 'tax_total' => null, 'round_off' => 0, 'total' => null, 'paid_total' => 0, 'balance' => null],
        'invoice_lines' => ['unit_price' => null, 'discount_amount' => 0, 'taxable' => null],
        'invoice_tax_lines' => ['amount' => null],
        'payments' => ['amount' => null],
        'online_payments' => ['amount' => null, 'dues_amount' => null, 'renewal_amount' => 0],
    ];

    public function up(): void
    {
        $this->resize(13, 3);
    }

    public function down(): void
    {
        $this->resize(12, 2);
    }

    private function resize(int $total, int $places): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $total, $places): void {
                foreach ($columns as $column => $default) {
                    $definition = $blueprint->decimal($column, $total, $places);

                    if ($default !== null) {
                        $definition->default($default);
                    }

                    $definition->change();
                }
            });
        }
    }
};
