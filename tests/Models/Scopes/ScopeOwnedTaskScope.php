<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * User-aware auto-scope discovered by convention (App\Models\Scopes\{Model}Scope)
 * for the ResourceScopeTest ScopeOwnedTask model. Filters rows to the current
 * sanctum user's ownership, proving the explicit-context user reaches the app's
 * global scope at query-execution time.
 *
 * Toggled per-test via the static $enabled flag so it only affects the one
 * test that asserts user-aware scoping.
 */
class ScopeOwnedTaskScope implements Scope
{
    public static bool $enabled = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (! static::$enabled) {
            return;
        }

        $user = auth('sanctum')->user();

        if (! $user) {
            $builder->whereRaw('1 = 0'); // fail closed
            return;
        }

        $builder->where('owner_id', $user->id);
    }
}
