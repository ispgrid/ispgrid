<?php

namespace Database\Seeders;

use App\Models\ConnectionType;
use Illuminate\Database\Seeder;

class ConnectionTypeSeeder extends Seeder
{
    /**
     * Defaults on first install only: operators rename and delete these, and an upgrade must not bring them back.
     */
    public function run(): void
    {
        if (ConnectionType::withTrashed()->exists()) {
            return;
        }

        foreach (['Home', 'Business'] as $name) {
            ConnectionType::query()->create(['name' => $name]);
        }
    }
}
