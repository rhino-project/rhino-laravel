<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserRoleFactory extends Factory
{
    protected $model = UserRole::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'organization_id' => Organization::factory(),
            'permissions' => ['*'],
        ];
    }
}
