<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 9999),
            'domain' => null,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
