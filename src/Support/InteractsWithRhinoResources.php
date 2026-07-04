<?php

namespace Rhino\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Convenience controller trait for custom (non-CRUD) controllers that need a
 * tenant-safe base query for a model — dashboards, reports, metrics.
 */
trait InteractsWithRhinoResources
{
    /**
     * Tenant-scoped base query for $modelClass using ambient context.
     */
    protected function scoped(string $modelClass): Builder
    {
        return app(ResourceScope::class)->query($modelClass);
    }

    /**
     * Run $metric against the scoped query only when the current user may
     * viewAny of $modelClass; otherwise return null.
     *
     * @return mixed
     */
    protected function ifCanView(string $modelClass, callable $metric): mixed
    {
        return Gate::allows('viewAny', $modelClass) ? $metric($this->scoped($modelClass)) : null;
    }
}
