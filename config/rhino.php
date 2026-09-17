<?php

return [
    'models' => [
        // 'users' => \App\Models\User::class,
    ],
    'route_groups' => [
        // 'tenant' => [
        //     'prefix' => '{organization}',
        //     // 'domain' => null, // Optionally constrain this group to a host.
        //     'middleware' => [\App\Http\Middleware\ResolveOrganizationFromRoute::class],
        //     'models' => '*',
        // ],
        // 'public' => [
        //     'prefix' => '',
        //     'middleware' => [],
        //     'models' => ['categories'],
        // ],
        //
        // The optional 'domain' key constrains a group's routes to a specific
        // host. This lets two groups share the same prefix while living on
        // different domains. A parameterized domain such as
        // '{organization}.example.com' exposes '{organization}' as a route
        // parameter, so it flows into ResolveOrganizationFromRoute just like a
        // path prefix. Groups without a 'domain' match any host (default).
        //
        // 'admin' => [
        //     'prefix' => '',
        //     'domain' => 'admin.example.com',
        //     'tenant' => false,
        //     'middleware' => [],
        //     'models' => '*',
        // ],
        //
        // The optional 'tenant' key declares whether the group has a tenant
        // boundary. It defaults to true: Rhino::query() inside the group fails
        // closed, throwing MissingTenantContext when an organization-scoped
        // model is queried with no organization resolved. Set it to false for a
        // group that legitimately spans every organization — a back-office or
        // admin group whose operators see all tenants' rows. In such a group
        // Rhino::query() applies no organization filter and does not throw; the
        // app's own user-aware global scopes, named scopes and policies still
        // apply, and an explicit inOrganization() still scopes.
        //
        // The group is read from the matched route's 'route_group' default, the
        // same value memberships and policies use. Rhino's generated CRUD routes
        // carry it already; tag your own custom routes to place them in a group:
        //
        //   Route::get('admin/dashboard', [AdminDashboardController::class, 'summary'])
        //       ->defaults('route_group', 'admin');
        //
        // Outside a request (queued jobs, console commands) no group resolves,
        // so the resolver keeps failing closed unless the caller names the
        // group it is acting as:
        //
        //   Rhino::inRouteGroup('admin')->query(Task::class);
        //   Rhino::forUser($operator)->inRouteGroup('admin')->query(Task::class);
        //
        // The group's own 'tenant' key still decides: naming a tenant group
        // there changes nothing, the query still fails closed.
        //
        // A group may opt into group-aware auth by setting 'auth' => true. When
        // set, the full auth route set (login, logout, password/recover,
        // password/reset, register) is registered under the group's
        // prefix/domain, tagged with the group's route_group. The legacy
        // unprefixed /auth/* set always remains for the default/no-group case.
        // An optional 'hooks' class (implementing Rhino\Contracts\AuthLifecycleHooks)
        // runs after each auth action and may reject it.
        //
        // 'driver' => [
        //     'prefix'     => 'driver',
        //     'auth'       => true,                          // register auth routes for this group
        //     'hooks'      => \App\Auth\DriverAuthHooks::class, // optional lifecycle hooks
        //     'middleware' => [],
        //     'models'     => ['trips'],
        // ],
        'default' => [
            'prefix' => '',
            'middleware' => [],
            'models' => '*',
        ],
    ],
    'auth' => [
        // Master flag for group membership enforcement. Default OFF: behavior is
        // byte-for-byte what it is today (no membership check; permission source
        // is the existing org-presence heuristic). When ON, an authenticated
        // user must have a user_roles membership row matching the request's
        // route_group (a NULL route_group row is a wildcard matching every group)
        // and, for tenant groups, the resolved organization — else 403. Permissions
        // then resolve from that matching membership row.
        'enforce_group_membership' => false,
    ],
    'multi_tenant' => [
        'organization_identifier_column' => 'id', // Options: 'id', 'slug', or any other column name
    ],

    // ------------------------------------------------------------------
    // Route key (member-endpoint lookup column)
    // ------------------------------------------------------------------
    // Which column the {id} URL segment is matched against on member
    // endpoints (show, update, destroy, restore, force-delete), e.g.
    // GET /api/jobs/{hash_id}.
    //
    // Resolution chain (first match wins):
    //   1. Model static:  public static string $routeKey = 'hash_id';
    //   2. This config value ('id' or null = not set, fall through)
    //   3. The model's getRouteKeyName() (Eloquent default: primary key)
    //
    // The chosen column MUST be unique and SHOULD be indexed — it is used
    // in a WHERE clause on every member request. This affects ONLY the URL
    // segment lookup: foreign keys in payloads, nested-operation ids,
    // `exists:` validation columns, and audit logs remain primary-key based.
    'route_key' => 'id',
    // ------------------------------------------------------------------
    // Named scopes: how many may be combined in one request
    // ------------------------------------------------------------------
    // A client may select several named scopes at once with the bracket form
    // (?scope[archived]=&scope[window][from]=...). Each one is an arbitrary
    // query fragment that may add joins or subqueries, so the number is capped:
    // a request over the cap returns 403 "Too many scopes requested".
    //
    // Three covers a base scope, a window, and one more predicate. Raise it if
    // your clients legitimately compose more, but remember that every extra
    // scope is another fragment in the same SQL statement.
    'max_scopes_per_request' => 3,

    // ------------------------------------------------------------------
    // Request classes (validation)
    // ------------------------------------------------------------------
    // A request class owns the whole shape/format contract of ONE action of
    // ONE model: it can see the current user, the organization, the route
    // group and — on update — the record being changed, so rules that depend
    // on any of them live in ordinary PHP instead of a role-keyed map.
    //
    //   namespace App\Http\Requests;
    //
    //   use Rhino\Http\Requests\ResourceRequest;
    //
    //   class TaskStoreRequest extends ResourceRequest
    //   {
    //       public function authorize(): bool { return $this->routeGroup() !== 'public'; }
    //       public function rules(): array { return ['title' => 'required|string|max:255']; }
    //   }
    //
    // Discovery, per action, first match wins:
    //   1. The 'map' below, when it names a class for this model and action.
    //      A class named here that does not exist (or is not a FormRequest) is
    //      a hard error — a silently ignored validation class is a security
    //      hole.
    //   2. Convention: {namespace}\{Model}StoreRequest and
    //      {namespace}\{Model}UpdateRequest, where {Model} is the model class
    //      basename (App\Models\Task → TaskStoreRequest). A class that simply
    //      is not there falls through silently.
    //   3. Neither: the model's own validation rules apply, unchanged.
    //
    // What the class validates is what gets written: only keys covered by a
    // rule that passed are persisted, so a field with no rule is dropped
    // rather than saved. The policy's permitted-attributes check still runs
    // first, on the raw client input, and organization_id is still applied by
    // the framework afterwards.
    //
    // Neither key is required — leaving this whole block out keeps convention
    // discovery working with the defaults below.
    'requests' => [
        // Namespace scanned for {Model}StoreRequest / {Model}UpdateRequest.
        'namespace' => 'App\\Http\\Requests',

        // Explicit per-model override, keyed by the SAME slug used in 'models'.
        'map' => [
            // 'tasks' => ['store' => \App\Api\CreateTask::class, 'update' => \App\Api\EditTask::class],
        ],
    ],

    'invitations' => [
        'expires_days' => env('INVITATION_EXPIRES_DAYS', 7),
        'allowed_roles' => null, // null means all roles can invite, or specify array of role slugs
    ],
    'nested' => [
        'path' => 'nested',
        'max_operations' => 50,
        'allowed_models' => null, // null = all registered models; or e.g. ['blogs', 'posts']
    ],
    'client_path' => env('RHINO_CLIENT_PATH'),
    'mobile_path' => env('RHINO_MOBILE_PATH'),
    'test_framework' => 'pest', // Options: 'pest', 'phpunit'
    'postman' => [
        'role_class' => 'App\Models\Role',
        'user_role_class' => 'App\Models\UserRole',
        'user_class' => 'App\Models\User',
    ],
];
