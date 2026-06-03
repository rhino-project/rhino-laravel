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
        'default' => [
            'prefix' => '',
            'middleware' => [],
            'models' => '*',
        ],
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
