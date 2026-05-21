<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('role_id')
            ->withTimestamps();
    }
}
