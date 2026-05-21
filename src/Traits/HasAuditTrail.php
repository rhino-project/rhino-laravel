<?php

namespace Rhino\Traits;

use Illuminate\Database\Eloquent\SoftDeletes;

trait HasAuditTrail
{
    /**
     * Get columns to exclude from audit logging.
     * Override this method in your model to customize.
     *
     * @return array<string>
     */
    public function getAuditExclude(): array
    {
        if (property_exists($this, 'auditExclude')) {
            return $this->auditExclude;
        }

        return ['password', 'remember_token'];
    }

    /**
     * Boot the trait — register model event listeners.
     */
    public static function bootHasAuditTrail(): void
    {
        static::created(function ($model) {
            $model->logAudit('created', null, $model->getAuditableAttributes());
        });

        static::updated(function ($model) {
            // Skip the updated event when the model is being restored
            // (the restored event already handles this)
            if (method_exists($model, 'trashed') && !$model->trashed() && $model->isDirty('deleted_at')) {
                return;
            }

            $changes = $model->getDirty();
            $original = collect($model->getOriginal())
                ->only(array_keys($changes))
                ->toArray();

            // Filter out excluded columns
            $changes = $model->filterAuditAttributes($changes);
            $original = $model->filterAuditAttributes($original);

            if (!empty($changes)) {
                $model->logAudit('updated', $original, $changes);
            }
        });

        static::deleted(function ($model) {
            $action = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                ? 'force_deleted'
                : 'deleted';
            $model->logAudit($action, $model->getAuditableAttributes(), null);
        });

        // Register restored event only if model uses SoftDeletes
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class))) {
            static::restored(function ($model) {
                $model->logAudit('restored', null, $model->getAuditableAttributes());
            });
        }
    }

    /**
     * Get the audit log entries for this model.
     */
    public function auditLogs(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\Rhino\Models\AuditLog::class, 'auditable');
    }

    /**
     * Write an audit log entry.
     */
    protected function logAudit(string $action, ?array $oldValues, ?array $newValues): void
    {
        // Ensure the audit_logs table exists before attempting to write
        if (!$this->auditLogTableExists()) {
            return;
        }

        $attributes = [
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        // Add organization_id if available on the request
        $organization = request()->attributes->get('organization');
        if ($organization && is_object($organization) && isset($organization->id)) {
            $attributes['organization_id'] = $organization->id;
        }

        \Rhino\Models\AuditLog::create($attributes);
    }

    /**
     * Get model attributes filtered by audit exclusions.
     */
    protected function getAuditableAttributes(): array
    {
        return $this->filterAuditAttributes($this->attributesToArray());
    }

    /**
     * Remove excluded columns from an attribute array.
     */
    protected function filterAuditAttributes(array $attributes): array
    {
        return collect($attributes)
            ->except($this->getAuditExclude())
            ->toArray();
    }

    /**
     * Check if the audit_logs table exists (cached per request).
     */
    protected function auditLogTableExists(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = \Illuminate\Support\Facades\Schema::hasTable('audit_logs');
        }

        return $exists;
    }
}
