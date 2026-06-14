<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    protected $model = Station::class;

    public function definition(): array
    {
        return [
            'mda_id' => Mda::factory(),
            'code' => strtoupper(fake()->unique()->lexify('STA???')),
            'name' => fake()->unique()->company().' Station',
            'description' => fake()->sentence(),
            'status' => 'active',
        ];
    }
}
