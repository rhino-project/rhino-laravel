<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    use HasFactory;

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

    /**
     * Get the user that owns this role assignment.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the role.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the organization.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
