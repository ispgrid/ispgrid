<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on the customer's mobile number, so a Selfcare login by mobile finds its connections without reading every
 * customer. FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('accounts', ['mobile'])) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->index('mobile');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('accounts', ['mobile'])) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropIndex(['mobile']);
            });
        }
    }
};
