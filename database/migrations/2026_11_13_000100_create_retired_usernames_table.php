<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usernames a customer gave up by a rename. Their sessions and login log stay under the old name (session records
 * must, by law), so no other customer may take it: it would see those records as its own. Filled from the renames
 * the activity log already holds. FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retired_usernames')) {
            Schema::create('retired_usernames', function (Blueprint $table) {
                $table->id();
                $table->string('username', 64);
                $table->unsignedBigInteger('account_id');
                $table->timestamp('created_at')->nullable();
                $table->unique(['username', 'account_id']);
            });
        }

        DB::table('activity_log')->where('event', 'username-changed')->where('subject_type', (new Account)->getMorphClass())
            ->orderBy('id')->select(['id', 'subject_id', 'attribute_changes', 'created_at'])
            ->chunkById(500, function ($renames): void {
                $rows = [];

                foreach ($renames as $rename) {
                    $old = json_decode((string) $rename->attribute_changes, true)['old']['username'] ?? null;

                    if (is_string($old) && $old !== '' && $rename->subject_id !== null) {
                        $rows[] = ['username' => $old, 'account_id' => (int) $rename->subject_id, 'created_at' => $rename->created_at];
                    }
                }

                DB::table('retired_usernames')->insertOrIgnore($rows);
            });
    }

    /**
     * Keeps the table: without it a customer could take a username whose sessions belong to another one.
     */
    public function down(): void {}
};
