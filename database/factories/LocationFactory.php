<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'state' => fake()->state(),
            'lga' => fake()->citySuffix(),
            'ward' => fake()->optional()->streetName(),
            'town' => fake()->city(),
            'is_urban_center' => fake()->boolean(),
            'status' => 'active',
        ];
    }
}
