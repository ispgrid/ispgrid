<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The item a manual invoice line was filled from, so reports can group by item later. Empty for every line written
 * by hand or before Items existed. A used item is never deleted, only made inactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('invoice_id')->constrained('items');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
        });
    }
};
