<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

class Organization extends Model
{
    use HasFactory, SoftDeletes, HasValidation, HidableColumns;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'description',
        'is_active',
    ];

    protected $validationRules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:organizations,slug',
        'domain' => 'nullable|string|max:255|unique:organizations,domain',
        'description' => 'nullable|string',
        'is_active' => 'boolean',
    ];

    protected $validationRulesStore = ['name', 'slug', 'domain', 'description', 'is_active'];
    protected $validationRulesUpdate = ['name', 'slug', 'domain', 'description', 'is_active'];

    public static $allowedFilters = ['name', 'slug', 'domain', 'is_active'];
    public static $allowedSorts = ['name', 'created_at'];
    public static $defaultSort = 'name';
    public static $allowedFields = ['id', 'name', 'slug', 'domain', 'description', 'is_active', 'created_at'];
    public static $allowedIncludes = ['users'];

    /**
     * Get the users that belong to this organization.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * Get the roles assigned to users in this organization.
     */
    public function roles()
    {
        return $this->hasManyThrough(Role::class, UserRole::class, 'organization_id', 'id', 'id', 'role_id');
    }
}
