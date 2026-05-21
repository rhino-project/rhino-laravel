<?php

namespace Rhino\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Organization;

trait BelongsToOrganization
{
    /**
     * Boot the trait.
     */
    protected static function bootBelongsToOrganization()
    {
        // Automatically add organization_id when creating
        static::creating(function ($model) {
            if (app()->runningInConsole()) {
                return;
            }
            
            if (request()->attributes->has('organization') && !$model->organization_id) {
                $organization = request()->attributes->get('organization');
                if ($organization instanceof Organization) {
                    $model->organization_id = $organization->id;
                }
            }
        });

        // Add global scope to filter by organization
        static::addGlobalScope('organization', function (Builder $builder) {
            if (app()->runningInConsole()) {
                return;
            }
            
            if (request()->attributes->has('organization')) {
                $organization = request()->attributes->get('organization');
                if ($organization instanceof Organization) {
                    $builder->where('organization_id', $organization->id);
                }
            }
        });
    }

    /**
     * Get the organization that owns the model.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include records for a specific organization.
     */
    public function scopeForOrganization(Builder $query, Organization $organization): Builder
    {
        return $query->where('organization_id', $organization->id);
    }
}
