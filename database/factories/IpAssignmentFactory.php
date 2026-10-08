<?php

namespace Database\Factories;

use App\Enums\IpAssignmentKind;
use App\Models\Account;
use App\Models\IpAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAssignment>
 */
class IpAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->leasedLine(),
            'ip_range_id' => null,
            'kind' => IpAssignmentKind::LanSubnet,
            'network' => fake()->unique()->ipv4().'/32',
            'gateway' => null,
            'vlan_id' => fake()->numberBetween(100, 4000),
            'notes' => null,
        ];
    }
}
