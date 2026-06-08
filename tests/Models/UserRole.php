<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'organization_id',
        'route_group',
        'permissions',
        'granted_permissions',
        'denied_permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
        'granted_permissions' => 'array',
        'denied_permissions' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
