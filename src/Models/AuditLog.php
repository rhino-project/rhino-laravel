<?php

namespace Rhino\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'old_values',
        'new_values',
        'user_id',
        'organization_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Get the auditable model (polymorphic).
     */
    public function auditable()
    {
        return $this->morphTo();
    }

    /**
     * Get the user who performed the action.
     */
    public function user()
    {
        return $this->belongsTo(
            config('auth.providers.users.model', 'App\Models\User')
        );
    }
}
