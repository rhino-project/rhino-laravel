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
        //     'middleware' => [],
        //     'models' => '*',
        // ],
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
