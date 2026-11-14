<?php

namespace Database\Factories;

use App\Enums\ItemSoldAs;
use App\Enums\ItemType;
use App\Enums\ServiceKind;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'type' => ItemType::Goods,
            'unit' => 'pcs',
            'item_code' => null,
            'taxable' => true,
            'price' => 999,
            'description' => null,
            'sold_as' => ItemSoldAs::OneTime,
            'service_kind' => null,
            'active' => true,
        ];
    }

    /**
     * A plain service, billed monthly.
     */
    public function service(): static
    {
        return $this->state(['type' => ItemType::Service, 'unit' => 'month', 'price' => 100,
            'sold_as' => ItemSoldAs::Service, 'service_kind' => ServiceKind::Plain]);
    }

    /**
     * A static IP service, billed monthly.
     */
    public function staticIp(): static
    {
        return $this->service()->state(['name' => 'Static IP', 'price' => 300, 'service_kind' => ServiceKind::StaticIp]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
