<?php

namespace Database\Seeders;

use App\Models\RadiusSetting;
use Illuminate\Database\Seeder;

class RadiusSettingSeeder extends Seeder
{
    /**
     * The single settings row isp reads. Existing values are never overwritten.
     */
    public function run(): void
    {
        RadiusSetting::query()->firstOrCreate(['id' => 1], [
            'expire_type' => 1,
            'disabled_users_pool' => 'disabled_pool',
            'reject_requests' => 30,
            'reject_session_timeout' => 7200,
            'default_session_timeout' => 64800,
            'count_and_reject' => 1,
            'reject_packet_interval' => 30,
        ]);
    }
}
