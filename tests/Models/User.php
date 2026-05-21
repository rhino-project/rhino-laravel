<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Rhino\Contracts\HasRoleBasedValidation;
use Rhino\Traits\HasPermissions;

class User extends Authenticatable implements HasRoleBasedValidation
{
    use HasApiTokens, HasPermissions;

    protected $fillable = [
        'name',
        'email',
        'password',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'user_roles')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function rolesInOrganization(Organization $organization)
    {
        return Role::whereIn('id', function ($query) use ($organization) {
            $query->select('role_id')
                ->from('user_roles')
                ->where('user_id', $this->id)
                ->where('organization_id', $organization->id);
        });
    }
}
