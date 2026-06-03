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
        'route_group',
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

        // If user is provided, create the membership (user_roles) row carrying
        // the invitation's route_group (+ org + role).
        if ($user) {
            $role = $this->role;
            $routeGroup = $this->route_group;

            if ($this->organization_id) {
                // Tenant invitation: attach via the organization relationship,
                // including route_group in the pivot. Skip if already a member.
                $organization = $this->organization;

                if (!$organization->users()->where('users.id', $user->id)->exists()) {
                    $organization->users()->attach($user->id, [
                        'role_id' => $role->id,
                        'route_group' => $routeGroup,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                // Non-tenant invitation (no org): create the user_roles row
                // directly with organization_id = null + route_group.
                $userRoleClass = config('rhino.models.user_roles', \App\Models\UserRole::class);

                $exists = $userRoleClass::query()
                    ->where('user_id', $user->id)
                    ->whereNull('organization_id')
                    ->where('role_id', $role->id)
                    ->where(function ($q) use ($routeGroup) {
                        $routeGroup === null
                            ? $q->whereNull('route_group')
                            : $q->where('route_group', $routeGroup);
                    })
                    ->exists();

                if (!$exists) {
                    $userRoleClass::create([
                        'user_id' => $user->id,
                        'organization_id' => null,
                        'role_id' => $role->id,
                        'route_group' => $routeGroup,
                    ]);
                }
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
