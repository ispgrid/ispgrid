<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's account ID (e.g. LINK-1000001): the full text as given, unique among all customers, deleted ones
 * too. Empty until the operator saves a start number in App settings. FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        // down() keeps the column, so running up() again after a rollback finds it in place.
        if (! Schema::hasColumn('accounts', 'account_number')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->string('account_number', 30)->nullable()->unique()->after('username');
            });
        }
    }

    /**
     * Keeps the column: dropping it would lose IDs customers already know.
     */
    public function down(): void {}
};
