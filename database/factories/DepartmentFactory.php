<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'mda_id' => Mda::factory(),
            'code' => strtoupper(fake()->unique()->lexify('DEP???')),
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->sentence(),
            'status' => 'active',
        ];
    }
}
