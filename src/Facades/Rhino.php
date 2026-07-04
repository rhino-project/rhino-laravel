<?php

namespace Rhino\Facades;

use Illuminate\Support\Facades\Facade;
use Rhino\Support\RhinoManager;

/**
 * @method static \Illuminate\Database\Eloquent\Builder query(string $modelClass)
 * @method static \Illuminate\Database\Eloquent\Builder scopedQuery(string $modelClass, ?string $namedScope = null)
 * @method static \Rhino\Support\PendingScopedContext forUser(\Illuminate\Contracts\Auth\Authenticatable $user, $organization = null)
 * @method static \Rhino\Support\RhinoContext context()
 *
 * @see \Rhino\Support\RhinoManager
 */
class Rhino extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RhinoManager::class;
    }
}
