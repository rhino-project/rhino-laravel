<?php

use Illuminate\Support\Facades\Route;
use Rhino\Controllers\AuthController;
use Rhino\Controllers\GlobalController;
use Rhino\Controllers\InvitationController;

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('password/recover', [AuthController::class, 'recoverPassword']);
    Route::post('password/reset', [AuthController::class, 'reset']);
    Route::post('register', [AuthController::class, 'registerWithInvitation']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

/*
|--------------------------------------------------------------------------
| Invitation Routes
|--------------------------------------------------------------------------
*/

Route::post('invitations/accept', [InvitationController::class, 'accept']);

/*
|--------------------------------------------------------------------------
| Auto-Generated CRUD Routes
|--------------------------------------------------------------------------
|
| Routes are generated per model from the global-controller config.
| Each model gets explicit routes visible in `php artisan route:list`.
|
| To override a specific action, define your custom route ABOVE this file's
| require statement in routes/api.php. The first registered route wins.
|
| Models can define:
|   - public static array $middleware = ['throttle:60,1'];
|   - public static array $middlewareActions = ['store' => ['verified']];
|   - public static array $exceptActions = ['delete'];
|
*/

$globalControllerConfig = config('rhino', []);
$allModels = $globalControllerConfig['models'] ?? [];
$routeGroups = $globalControllerConfig['route_groups'] ?? [];

// Determine if any group is named 'tenant' (has org-scoped routes)
$hasTenantGroup = isset($routeGroups['tenant']);

// Invitation routes (protected, require auth + organization context in tenant group)
if ($hasTenantGroup) {
    $tenantGroupConfig = $routeGroups['tenant'];
    $tenantPrefix = $tenantGroupConfig['prefix'] ?? '';
    $tenantMiddleware = array_filter(array_merge(
        ['auth:sanctum'],
        $tenantGroupConfig['middleware'] ?? []
    ));
    $invitationPrefix = $tenantPrefix ? "{$tenantPrefix}/invitations" : 'invitations';

    Route::prefix($invitationPrefix)
        ->middleware($tenantMiddleware)
        ->group(function () {
            Route::get('/', [InvitationController::class, 'index']);
            Route::post('/', [InvitationController::class, 'store']);
            Route::post('{id}/resend', [InvitationController::class, 'resend']);
            Route::delete('{id}', [InvitationController::class, 'cancel']);
        });
}

// Nested create/update endpoint (one request, multiple operations, single transaction)
$nestedConfig = $globalControllerConfig['nested'] ?? [];
$nestedPath = $nestedConfig['path'] ?? 'nested';
if ($hasTenantGroup) {
    $tenantGroupConfig = $routeGroups['tenant'];
    $tenantPrefix = $tenantGroupConfig['prefix'] ?? '';
    $nestedMiddleware = array_filter(array_merge(
        ['auth:sanctum'],
        $tenantGroupConfig['middleware'] ?? []
    ));
    $nestedPrefix = $tenantPrefix ? "{$tenantPrefix}/{$nestedPath}" : $nestedPath;
} else {
    $nestedMiddleware = ['auth:sanctum'];
    $nestedPrefix = $nestedPath;
}
Route::post($nestedPrefix, [GlobalController::class, 'nested'])
    ->middleware($nestedMiddleware)
    ->name('nested');

// Sort route groups: literal prefixes first, parameterized prefixes (containing {) last.
// This prevents wildcard routes like {organization}/posts from capturing literal routes like admin/posts.
$sortedRouteGroups = $routeGroups;
uasort($sortedRouteGroups, function ($a, $b) {
    $aHasParam = str_contains($a['prefix'] ?? '', '{');
    $bHasParam = str_contains($b['prefix'] ?? '', '{');
    return $aHasParam <=> $bHasParam;
});

// Register per-model CRUD routes for each route group
foreach ($sortedRouteGroups as $groupKey => $groupConfig) {
    $groupPrefix = $groupConfig['prefix'] ?? '';
    $groupMiddleware = $groupConfig['middleware'] ?? [];
    $groupModels = $groupConfig['models'] ?? '*';

    // Resolve which models belong to this group
    if ($groupModels === '*') {
        $modelsForGroup = $allModels;
    } else {
        $modelsForGroup = array_intersect_key($allModels, array_flip((array) $groupModels));
    }

    foreach ($modelsForGroup as $slug => $modelClass) {
        if (!class_exists($modelClass)) {
            continue;
        }

        // Build middleware stack
        $middleware = [];

        // 'public' group name skips auth:sanctum
        if ($groupKey !== 'public') {
            $middleware[] = 'auth:sanctum';
        }

        // Group-level middleware
        $middleware = array_merge($middleware, $groupMiddleware);

        // Model-level middleware (applied to all actions)
        if (property_exists($modelClass, 'middleware')) {
            $middleware = array_merge($middleware, $modelClass::$middleware);
        }

        // Per-action middleware
        $actionMiddleware = property_exists($modelClass, 'middlewareActions')
            ? $modelClass::$middlewareActions
            : [];

        // Excepted actions (actions to skip)
        $exceptActions = property_exists($modelClass, 'exceptActions')
            ? $modelClass::$exceptActions
            : [];

        // Build route prefix: group prefix + model slug
        $prefix = $groupPrefix ? "{$groupPrefix}/{$slug}" : $slug;

        // Check if the model uses SoftDeletes
        $usesSoftDeletes = in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($modelClass)
        );

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(function () use ($slug, $groupKey, $actionMiddleware, $exceptActions, $usesSoftDeletes) {
                if (!in_array('index', $exceptActions)) {
                    Route::get('/', [GlobalController::class, 'index'])
                        ->defaults('model', $slug)
                        ->defaults('route_group', $groupKey)
                        ->middleware($actionMiddleware['index'] ?? [])
                        ->name("{$groupKey}.{$slug}.index");
                }

                if (!in_array('store', $exceptActions)) {
                    Route::post('/', [GlobalController::class, 'store'])
                        ->defaults('model', $slug)
                        ->defaults('route_group', $groupKey)
                        ->middleware($actionMiddleware['store'] ?? [])
                        ->name("{$groupKey}.{$slug}.store");
                }

                // Soft Delete routes — registered BEFORE {id} routes to avoid wildcard capture
                if ($usesSoftDeletes) {
                    if (!in_array('trashed', $exceptActions)) {
                        Route::get('trashed', [GlobalController::class, 'trashed'])
                            ->defaults('model', $slug)
                            ->defaults('route_group', $groupKey)
                            ->middleware($actionMiddleware['trashed'] ?? [])
                            ->name("{$groupKey}.{$slug}.trashed");
                    }

                    if (!in_array('restore', $exceptActions)) {
                        Route::post('{id}/restore', [GlobalController::class, 'restore'])
                            ->defaults('model', $slug)
                            ->defaults('route_group', $groupKey)
                            ->middleware($actionMiddleware['restore'] ?? [])
                            ->name("{$groupKey}.{$slug}.restore");
                    }

                    if (!in_array('forceDelete', $exceptActions)) {
                        Route::delete('{id}/force-delete', [GlobalController::class, 'forceDelete'])
                            ->defaults('model', $slug)
                            ->defaults('route_group', $groupKey)
                            ->middleware($actionMiddleware['forceDelete'] ?? [])
                            ->name("{$groupKey}.{$slug}.forceDelete");
                    }
                }

                if (!in_array('show', $exceptActions)) {
                    Route::get('{id}', [GlobalController::class, 'show'])
                        ->defaults('model', $slug)
                        ->defaults('route_group', $groupKey)
                        ->middleware($actionMiddleware['show'] ?? [])
                        ->name("{$groupKey}.{$slug}.show");
                }

                if (!in_array('update', $exceptActions)) {
                    Route::put('{id}', [GlobalController::class, 'update'])
                        ->defaults('model', $slug)
                        ->defaults('route_group', $groupKey)
                        ->middleware($actionMiddleware['update'] ?? [])
                        ->name("{$groupKey}.{$slug}.update");
                }

                if (!in_array('destroy', $exceptActions)) {
                    Route::delete('{id}', [GlobalController::class, 'destroy'])
                        ->defaults('model', $slug)
                        ->defaults('route_group', $groupKey)
                        ->middleware($actionMiddleware['destroy'] ?? [])
                        ->name("{$groupKey}.{$slug}.destroy");
                }
            });
    }
}
