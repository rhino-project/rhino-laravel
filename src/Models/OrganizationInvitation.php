<?php

namespace Rhino\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OrganizationInvitation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'email',
        'role_id',
        'token',
        'invited_by',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }
            
            if (empty($invitation->expires_at)) {
                $days = config('rhino.invitations.expires_days', 7);
                $invitation->expires_at = Carbon::now()->addDays($days);
            }
        });
    }

    /**
     * Get the organization that owns the invitation.
     */
    public function organization()
    {
        return $this->belongsTo(config('rhino.models.organizations', \App\Models\Organization::class));
    }

    /**
     * Get the role for the invitation.
     */
    public function role()
    {
        return $this->belongsTo(config('rhino.models.roles', \App\Models\Role::class));
    }

    /**
     * Get the user who sent the invitation.
     */
    public function invitedBy()
    {
        return $this->belongsTo(config('rhino.models.users', \App\Models\User::class), 'invited_by');
    }

    /**
     * Check if the invitation is expired.
     */
    public function isExpired(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if the invitation is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    /**
     * Accept the invitation.
     * 
     * @param \App\Models\User|null $user The user accepting the invitation (null if new user)
     * @return bool
     */
    public function accept($user = null): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->status = 'accepted';
        $this->accepted_at = Carbon::now();
        $this->save();

        // If user is provided, add them to the organization
        if ($user) {
            $organization = $this->organization;
            $role = $this->role;

            // Check if user is already in organization
            if (!$organization->users()->where('users.id', $user->id)->exists()) {
                $organization->users()->attach($user->id, [
                    'role_id' => $role->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return true;
    }

    /**
     * Scope to get only pending invitations.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to get only expired invitations.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '<=', now());
    }
}
