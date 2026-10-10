<?php

namespace Database\Factories;

use App\Models\Radacct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Radacct>
 */
class RadacctFactory extends Factory
{
    /**
     * An open PPPoE session that started an hour ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->subHour();

        return [
            'acctsessionid' => fake()->unique()->regexify('[0-9a-f]{8}'),
            'acctuniqueid' => fake()->unique()->md5(),
            'username' => fake()->unique()->regexify('[a-z]{8}'),
            'nasipaddress' => '10.0.0.1',
            'acctstarttime' => $start,
            'acctupdatetime' => $start,
            'acctstoptime' => null,
            'acctsessiontime' => 0,
            'acctinputoctets' => 0,
            'acctoutputoctets' => 0,
            'callingstationid' => 'BC:24:11:00:30:20',
            'framedipaddress' => fake()->localIpv4(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['acctstoptime' => now(), 'acctterminatecause' => 'User-Request']);
    }
}
