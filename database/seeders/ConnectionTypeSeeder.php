<?php

namespace Database\Seeders;

use App\Models\ConnectionType;
use Illuminate\Database\Seeder;

class ConnectionTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Home', 'Business'] as $name) {
            ConnectionType::withTrashed()->firstOrCreate(['name' => $name]);
        }
    }
}
