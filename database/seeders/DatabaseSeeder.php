<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Reference data for every install (safe to run again on upgrade).
     */
    public function run(): void
    {
        $this->call([
            RadiusSettingSeeder::class,
            AppSettingSeeder::class,
            ConnectionTypeSeeder::class,
            RadiusAttributeSeeder::class,
        ]);

        if (app()->isLocal()) {
            $user = User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password', 'email_verified_at' => now()],
            );

            if ($user->roles()->doesntExist()) {
                $user->assignRole(Role::ADMIN);
            }
        }
    }
}
