<?php

use Illuminate\Support\Facades\Route;
use Rhino\Controllers\AuthController;
use Rhino\Controllers\GlobalController;
use Rhino\Controllers\InvitationController;
use Rhino\Http\Middleware\EnforceGroupMembership;

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

// Fail fast on route groups that would silently shadow each other (same prefix
// + intersecting host-set + overlapping models). This enforces that, without a
// distinguishing domain, two or more overlapping groups need distinct prefixes.
\Rhino\Routing\RouteGroupValidator::validate($routeGroups, $allModels);

// Determine if any group is named 'tenant' (has org-scoped routes)
$hasTenantGroup = isset($routeGroups['tenant']);

// Extract the parameter names from a (possibly parameterized) domain, e.g.
// '{organization}.example.com' => ['organization']. Used to constrain each
// domain parameter to a single host label.
$rhinoDomainParams = function (?string $domain): array {
    if ($domain === null || $domain === '' || !preg_match_all('/\{(\w+)\}/', $domain, $matches)) {
        return [];
    }

    return $matches[1];
};

// Build a route registrar for a group's (domain, prefix). When a domain is set
// the group's routes are constrained to that host; parameterized domains such
// as '{organization}.example.com' additionally constrain each domain parameter
// to a single host label ('[^.]+') so it cannot capture dots / multiple labels
// — matching path-segment semantics and keeping parity with the other stacks.
$rhinoRegistrar = function (?string $domain, string $prefix) use ($rhinoDomainParams) {
    $hasDomain = $domain !== null && $domain !== '';

    $registrar = $hasDomain
        ? Route::domain($domain)->prefix($prefix)
        : Route::prefix($prefix);

    $params = $rhinoDomainParams($domain);
    if ($params) {
        $registrar->where(array_fill_keys($params, '[^.]+'));
    }

    return $registrar;
};

// Group-aware auth routes (Decision 9.A): for each group with `auth: true`,
// register the full auth route set under the group's prefix/domain, tagged with
// the group's route_group default. The legacy unprefixed /auth/* set above stays
// for the default/no-group case. The 'public' group is never auth-enabled.
$enforceMembership = (bool) (config('rhino.auth.enforce_group_membership', false));

// §11.1: An auth-enabled group with an empty prefix AND no domain is routing-
// indistinguishable from the legacy unprefixed /auth/* set — it simply IS the
// default/legacy auth. Rather than register a second, colliding route set (which
// the legacy set would shadow, leaving its route_group/hooks/membership dead), we
// let the legacy auth routes adopt that group's route_group default below. The
// RouteGroupValidator has already guaranteed there is at most ONE such group
// (two or more throw at boot), so this is unambiguous.
$rhinoDefaultAuthGroupKey = null;
foreach ($routeGroups as $groupKey => $groupConfig) {
    if ($groupKey === 'public' || empty($groupConfig['auth'])) {
        continue;
    }

    $hasPrefix = ($groupConfig['prefix'] ?? '') !== '';
    $hasDomain = ($groupConfig['domain'] ?? null) !== null && ($groupConfig['domain'] ?? null) !== '';

    if (!$hasPrefix && !$hasDomain) {
        $rhinoDefaultAuthGroupKey = $groupKey;
        break;
    }
}

foreach ($routeGroups as $groupKey => $groupConfig) {
    if ($groupKey === 'public' || empty($groupConfig['auth'])) {
        continue;
    }

    // The empty-prefix + no-domain auth group is served by the legacy auth
    // routes (which adopt its route_group below); do not register a colliding set.
    if ($groupKey === $rhinoDefaultAuthGroupKey) {
        continue;
    }

    $authGroupPrefix = $groupConfig['prefix'] ?? '';
    $authGroupDomain = $groupConfig['domain'] ?? null;
    $authGroupMiddleware = $groupConfig['middleware'] ?? [];

    $authPrefix = $authGroupPrefix ? "{$authGroupPrefix}/auth" : 'auth';

    $rhinoRegistrar($authGroupDomain, $authPrefix)
        ->middleware($authGroupMiddleware)
        ->group(function () use ($groupKey, $authGroupMiddleware) {
            Route::post('login', [AuthController::class, 'login'])
                ->defaults('route_group', $groupKey);
            Route::post('password/recover', [AuthController::class, 'recoverPassword'])
                ->defaults('route_group', $groupKey);
            Route::post('password/reset', [AuthController::class, 'reset'])
                ->defaults('route_group', $groupKey);
            Route::post('register', [AuthController::class, 'registerWithInvitation'])
                ->defaults('route_group', $groupKey);
            Route::post('logout', [AuthController::class, 'logout'])
                ->defaults('route_group', $groupKey)
                ->middleware('auth:sanctum');
        });
}

/*
|--------------------------------------------------------------------------
| Legacy Auth Routes (default / no-group)
|--------------------------------------------------------------------------
|
| The legacy unprefixed /auth/* set maps to the default/no-group case and has
| NO host constraint, so it would match on any host. It is registered AFTER the
| per-group auth routes above so that, on a group's own domain (e.g. an
| auth-enabled group with empty prefix + a domain), the host-scoped per-group
| route wins and the group's route_group / hooks / membership checks fire — the
| legacy route no longer shadows it.
*/

// §11.1: When exactly one auth-enabled group has an empty prefix + no domain,
// these legacy routes ARE that group's auth: they adopt its route_group default
// so the controller resolves the group, its hooks fire, and membership engages
// (no spurious 403). With no such group, route_group stays null — today's
// group-less legacy auth, byte-for-byte unchanged.
Route::prefix('auth')->group(function () use ($rhinoDefaultAuthGroupKey) {
    $applyDefault = function (\Illuminate\Routing\Route $route) use ($rhinoDefaultAuthGroupKey) {
        if ($rhinoDefaultAuthGroupKey !== null) {
            $route->defaults('route_group', $rhinoDefaultAuthGroupKey);
        }

        return $route;
    };

    $applyDefault(Route::post('login', [AuthController::class, 'login']));
    $applyDefault(Route::post('password/recover', [AuthController::class, 'recoverPassword']));
    $applyDefault(Route::post('password/reset', [AuthController::class, 'reset']));
    $applyDefault(Route::post('register', [AuthController::class, 'registerWithInvitation']));
    $applyDefault(Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum'));
});

// Invitation routes (protected, require auth + organization context in tenant group)
if ($hasTenantGroup) {
    $tenantGroupConfig = $routeGroups['tenant'];
    $tenantPrefix = $tenantGroupConfig['prefix'] ?? '';
    $tenantDomain = $tenantGroupConfig['domain'] ?? null;
    $tenantMiddleware = array_filter(array_merge(
        ['auth:sanctum'],
        $tenantGroupConfig['middleware'] ?? []
    ));
    $invitationPrefix = $tenantPrefix ? "{$tenantPrefix}/invitations" : 'invitations';

    $invitationRegistrar = $rhinoRegistrar($tenantDomain, $invitationPrefix);

    $invitationRegistrar
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
$nestedDomain = null;
if ($hasTenantGroup) {
    $tenantGroupConfig = $routeGroups['tenant'];
    $tenantPrefix = $tenantGroupConfig['prefix'] ?? '';
    $nestedDomain = $tenantGroupConfig['domain'] ?? null;
    $nestedMiddleware = array_filter(array_merge(
        ['auth:sanctum'],
        $tenantGroupConfig['middleware'] ?? []
    ));
    $nestedPrefix = $tenantPrefix ? "{$tenantPrefix}/{$nestedPath}" : $nestedPath;
} else {
    $nestedMiddleware = ['auth:sanctum'];
    $nestedPrefix = $nestedPath;
}
$nestedRoute = Route::post($nestedPrefix, [GlobalController::class, 'nested'])
    ->middleware($nestedMiddleware)
    ->name('nested');
if ($nestedDomain !== null && $nestedDomain !== '') {
    $nestedRoute->domain($nestedDomain);
    foreach ($rhinoDomainParams($nestedDomain) as $param) {
        $nestedRoute->where($param, '[^.]+');
    }
}

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
    $groupDomain = $groupConfig['domain'] ?? null;
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

        // §11.2: When membership enforcement is on, the membership gate must run
        // BEFORE the group middleware (e.g. ResolveOrganizationFromRoute) so that
        // an authenticated non-member gets the design's 403 instead of the org
        // resolver's info-hiding 404. The gate resolves the org itself (from the
        // route's {organization} param / identifier column) to perform the full
        // (group, org) membership check. A genuinely missing org still 404s via
        // ResolveOrganizationFromRoute, which runs afterwards.
        //
        // When enforcement is OFF (default), the gate is not added at all and the
        // group middleware runs exactly as before — keeping today's 404
        // info-hiding and the flag-off stack byte-for-byte identical.
        if ($groupKey !== 'public' && $enforceMembership) {
            $middleware[] = EnforceGroupMembership::class;
        }

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

        // Build the route registrar. When the group declares a domain, the
        // group's routes are constrained to that host (e.g. 'admin.example.com'
        // or a parameterized '{organization}.example.com'). Domain parameters
        // are exposed as route parameters, so '{organization}' flows into
        // ResolveOrganizationFromRoute exactly like a path prefix would.
        $registrar = $rhinoRegistrar($groupDomain, $prefix);

        $registrar
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
