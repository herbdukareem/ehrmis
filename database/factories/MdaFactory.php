<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Mda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mda>
 */
class MdaFactory extends Factory
{
    protected $model = Mda::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('MDA???')),
            'name' => fake()->unique()->company(),
            'description' => fake()->sentence(),
            'status' => 'active',
        ];
    }
}
