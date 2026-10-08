<?php

namespace Database\Seeders;

use App\Models\RadiusAttribute;
use Illuminate\Database\Seeder;

class RadiusAttributeSeeder extends Seeder
{
    /**
     * Attributes staff may add as custom reply attributes. Not listed on purpose: Framed-IP-Address,
     * Framed-Pool, Mikrotik-Rate-Limit, Mikrotik-Address-List (own form fields) and Session-Timeout,
     * Reply-Message and the Mikrotik limit attributes (set by isp).
     */
    public function run(): void
    {
        $attributes = [
            ['Framed-Route', 'text', true, 'Route a subnet to the customer: "<subnet> <gateway> <metric>"'],
            ['Framed-IP-Netmask', 'ipv4', false, 'Netmask for the customer address'],
            ['Framed-IPv6-Prefix', 'ipv6-prefix', true, 'IPv6 prefix on the customer link'],
            ['Delegated-IPv6-Prefix', 'ipv6-prefix', false, 'IPv6 prefix delegated to the customer router'],
            ['Idle-Timeout', 'integer', false, 'Seconds of inactivity before the session ends'],
            ['Acct-Interim-Interval', 'integer', false, 'Seconds between interim accounting updates'],
            ['Mikrotik-Group', 'text', false, 'Router-side profile or group name'],
            ['Mikrotik-Delegated-IPv6-Pool', 'text', false, 'Router IPv6 pool for prefix delegation'],
        ];

        foreach ($attributes as [$name, $type, $repeatable, $description]) {
            RadiusAttribute::query()->firstOrCreate(['name' => $name], [
                'type' => $type,
                'repeatable' => $repeatable,
                'description' => $description,
            ]);
        }
    }
}
