<?php

namespace Rhino\Commands;

use Illuminate\Console\Command;

class ExportPostmanCommand extends Command
{
    protected $signature = 'rhino:export-postman
                            {--output=postman_collection.json : Output file path}
                            {--base-url=http://localhost:8000/api : Base URL for requests}
                            {--project-name= : Collection name (defaults to app name)}';

    protected $description = 'Generate a Postman Collection v2.1 for all registered models with Query Builder examples and authentication routes';

    public function handle(): int
    {
        $outputPath = $this->option('output');
        $baseUrl = rtrim($this->option('base-url'), '/');
        $projectName = $this->option('project-name') ?: config('app.name', 'API');

        $allModels = config('rhino.models', []);
        $routeGroups = config('rhino.route_groups', []);

        $hasMultipleGroups = count($routeGroups) > 1;
        $needsOrgVariable = $this->anyGroupHasOrgPrefix($routeGroups);

        $variables = $this->buildCollectionVariables($baseUrl, $needsOrgVariable);
        $items = [];

        $items[] = $this->buildAuthFolder($baseUrl);

        if ($hasMultipleGroups) {
            // Multiple groups: Project -> GroupName -> resources
            foreach ($routeGroups as $groupKey => $groupConfig) {
                $groupModels = $this->resolveModelsForGroup($allModels, $groupConfig);
                if (empty($groupModels)) {
                    continue;
                }

                $groupPrefix = $groupConfig['prefix'] ?? '';
                $groupHasOrgPrefix = $this->prefixHasParam($groupPrefix);

                $groupItems = [];
                foreach ($groupModels as $slug => $modelClass) {
                    if (! class_exists($modelClass)) {
                        $this->warn("Model class does not exist: {$modelClass}");
                        continue;
                    }

                    $modelMeta = $this->introspectModel($modelClass, $slug);
                    $groupItems[] = [
                        'name' => $slug,
                        'item' => $this->buildActionFolders($slug, $modelMeta, $modelClass, $groupPrefix, $baseUrl),
                    ];
                }

                if (! empty($groupItems)) {
                    $items[] = [
                        'name' => $groupKey,
                        'item' => $groupItems,
                    ];
                }
            }
        } else {
            // Single group: Project -> resources (flat, backwards compatible)
            $groupConfig = reset($routeGroups);
            $groupPrefix = $groupConfig['prefix'] ?? '';
            $groupModels = $this->resolveModelsForGroup($allModels, $groupConfig ?: []);

            foreach ($groupModels as $slug => $modelClass) {
                if (! class_exists($modelClass)) {
                    $this->warn("Model class does not exist: {$modelClass}");
                    continue;
                }

                $modelMeta = $this->introspectModel($modelClass, $slug);
                $items[] = [
                    'name' => $slug,
                    'item' => $this->buildActionFolders($slug, $modelMeta, $modelClass, $groupPrefix, $baseUrl),
                ];
            }
        }

        $collection = [
            'info' => [
                'name' => $projectName,
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => $variables,
            'item' => $items,
        ];

        $json = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Failed to encode collection JSON.');
            return Command::FAILURE;
        }

        $written = file_put_contents($outputPath, $json);
        if ($written === false) {
            $this->error("Failed to write file: {$outputPath}");
            return Command::FAILURE;
        }

        $this->info("Postman collection written to {$outputPath}");
        return Command::SUCCESS;
    }

    private function anyGroupHasOrgPrefix(array $routeGroups): bool
    {
        foreach ($routeGroups as $groupConfig) {
            if ($this->prefixHasParam($groupConfig['prefix'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function prefixHasParam(string $prefix): bool
    {
        return ! empty($prefix) && str_contains($prefix, '{');
    }

    private function resolveModelsForGroup(array $allModels, array $groupConfig): array
    {
        $groupModels = $groupConfig['models'] ?? '*';
        if ($groupModels === '*') {
            return $allModels;
        }
        return array_intersect_key($allModels, array_flip((array) $groupModels));
    }

    private function buildCollectionVariables(string $baseUrl, bool $needsOrgVariable): array
    {
        $vars = [
            ['key' => 'baseUrl', 'value' => $baseUrl],
            ['key' => 'modelId', 'value' => '1'],
            ['key' => 'token', 'value' => ''],
        ];
        if ($needsOrgVariable) {
            $vars[] = ['key' => 'organization', 'value' => 'organization-1'];
        }
        return $vars;
    }

    private function buildAuthFolder(string $baseUrl): array
    {
        $headers = $this->defaultHeaders();

        $loginTestScript = "const json = pm.response.json();\nif (json.token) {\n    pm.collectionVariables.set(\"token\", json.token);\n}\nif (json.organization_slug) {\n    pm.collectionVariables.set(\"organization\", json.organization_slug);\n}";

        return [
            'name' => 'Authentication',
            'item' => [
                $this->requestItem(
                    'Login',
                    'POST',
                    '{{baseUrl}}/auth/login',
                    [],
                    array_merge($headers, [['key' => 'Content-Type', 'value' => 'application/json']]),
                    ['email' => 'user@example.com', 'password' => 'password'],
                    $loginTestScript
                ),
                $this->requestItem(
                    'Logout',
                    'POST',
                    '{{baseUrl}}/auth/logout',
                    [],
                    $headers
                ),
                $this->requestItem(
                    'Password recover',
                    'POST',
                    '{{baseUrl}}/auth/password/recover',
                    [],
                    array_merge($headers, [['key' => 'Content-Type', 'value' => 'application/json']]),
                    ['email' => 'user@example.com']
                ),
                $this->requestItem(
                    'Password reset',
                    'POST',
                    '{{baseUrl}}/auth/password/reset',
                    [],
                    array_merge($headers, [['key' => 'Content-Type', 'value' => 'application/json']]),
                    ['token' => '{{token}}', 'email' => 'user@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password']
                ),
                $this->requestItem(
                    'Register (with invitation)',
                    'POST',
                    '{{baseUrl}}/auth/register',
                    [],
                    array_merge($headers, [['key' => 'Content-Type', 'value' => 'application/json']]),
                    ['invitation_token' => '{{token}}', 'name' => 'New User', 'password' => 'password', 'password_confirmation' => 'password']
                ),
                $this->requestItem(
                    'Accept invitation',
                    'POST',
                    '{{baseUrl}}/invitations/accept',
                    [],
                    array_merge($headers, [['key' => 'Content-Type', 'value' => 'application/json']]),
                    ['token' => 'invitation-token']
                ),
            ],
        ];
    }

    private function getModelProperty(string $modelClass, string $property, mixed $default): mixed
    {
        if (! property_exists($modelClass, $property)) {
            return $default;
        }
        try {
            $ref = new \ReflectionProperty($modelClass, $property);
            $ref->setAccessible(true);
            $instance = $ref->isStatic() ? null : new $modelClass;
            $value = $ref->getValue($instance);
            return $value ?? $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function introspectModel(string $modelClass, string $slug): array
    {
        $exceptActions = property_exists($modelClass, 'exceptActions')
            ? $modelClass::$exceptActions
            : [];
        $usesSoftDeletes = in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($modelClass)
        );

        $allowedFilters = $this->getModelProperty($modelClass, 'allowedFilters', []);
        $allowedSorts = $this->getModelProperty($modelClass, 'allowedSorts', []);
        $allowedFields = $this->getModelProperty($modelClass, 'allowedFields', []);
        $allowedIncludes = $this->getModelProperty($modelClass, 'allowedIncludes', []);
        $allowedSearch = $this->getModelProperty($modelClass, 'allowedSearch', []);
        $allowedScopes = $this->getModelProperty($modelClass, 'allowedScopes', []);
        $defaultSort = $this->getModelProperty($modelClass, 'defaultSort', null);

        $validationRules = $this->getModelProperty($modelClass, 'validationRules', []);
        $validationRulesStore = $this->getModelProperty($modelClass, 'validationRulesStore', []);
        $validationRulesUpdate = $this->getModelProperty($modelClass, 'validationRulesUpdate', []);

        return [
            'slug' => $slug,
            'exceptActions' => is_array($exceptActions) ? $exceptActions : [],
            'usesSoftDeletes' => $usesSoftDeletes,
            'allowedFilters' => is_array($allowedFilters) ? $allowedFilters : [],
            'allowedSorts' => is_array($allowedSorts) ? $allowedSorts : [],
            'allowedFields' => is_array($allowedFields) ? $allowedFields : [],
            'allowedIncludes' => is_array($allowedIncludes) ? $allowedIncludes : [],
            'allowedSearch' => is_array($allowedSearch) ? $allowedSearch : [],
            'allowedScopes' => is_array($allowedScopes) ? $allowedScopes : [],
            'defaultSort' => $defaultSort,
            'validationRules' => $validationRules,
            'validationRulesStore' => $validationRulesStore,
            'validationRulesUpdate' => $validationRulesUpdate,
        ];
    }

    private function buildActionFolders(
        string $slug,
        array $modelMeta,
        string $modelClass,
        string $groupPrefix,
        string $baseUrl
    ): array {
        $folders = [];
        $basePath = $this->basePath($slug, $groupPrefix);
        $exceptActions = $modelMeta['exceptActions'];

        if (! in_array('index', $exceptActions)) {
            $folders[] = [
                'name' => 'Index',
                'item' => $this->buildIndexRequests($basePath, $slug, $modelMeta),
            ];
        }

        if (! in_array('show', $exceptActions)) {
            $folders[] = [
                'name' => 'Show',
                'item' => $this->buildShowRequests($basePath, $slug, $modelMeta),
            ];
        }

        if (! in_array('store', $exceptActions)) {
            $storeBodies = $this->buildStoreBodies($modelClass, $modelMeta);
            $folders[] = [
                'name' => 'Store',
                'item' => $this->buildStoreRequests($basePath, $slug, $storeBodies),
            ];
        }

        if (! in_array('update', $exceptActions)) {
            $updateBodies = $this->buildUpdateBodies($modelClass, $modelMeta);
            $folders[] = [
                'name' => 'Update',
                'item' => $this->buildUpdateRequests($basePath, $slug, $updateBodies),
            ];
        }

        if (! in_array('destroy', $exceptActions)) {
            $folders[] = [
                'name' => 'Destroy',
                'item' => $this->buildDestroyRequests($basePath, $slug),
            ];
        }

        if ($modelMeta['usesSoftDeletes']) {
            if (! in_array('trashed', $exceptActions)) {
                $folders[] = [
                    'name' => 'Trashed',
                    'item' => $this->buildTrashedRequests($basePath, $slug, $modelMeta),
                ];
            }
            if (! in_array('restore', $exceptActions)) {
                $folders[] = [
                    'name' => 'Restore',
                    'item' => $this->buildRestoreRequests($basePath, $slug),
                ];
            }
            if (! in_array('forceDelete', $exceptActions)) {
                $folders[] = [
                    'name' => 'Force Delete',
                    'item' => $this->buildForceDeleteRequests($basePath, $slug),
                ];
            }
        }

        return $folders;
    }

    private function basePath(string $slug, string $groupPrefix): string
    {
        if (! empty($groupPrefix)) {
            // Replace {organization} or similar route parameters with Postman variables
            $postmanPrefix = preg_replace('/\{(\w+)\}/', '{{$1}}', $groupPrefix);
            return '{{baseUrl}}/' . $postmanPrefix . '/' . $slug;
        }
        return '{{baseUrl}}/' . $slug;
    }

    private function buildIndexRequests(string $basePath, string $slug, array $modelMeta): array
    {
        $requests = [];
        $headers = $this->defaultHeaders();

        $requests[] = $this->requestItem('List all', 'GET', $basePath, [], $headers);

        foreach ($modelMeta['allowedFilters'] as $filter) {
            $requests[] = $this->requestItem(
                'Filter by ' . $filter,
                'GET',
                $basePath,
                ['filter[' . $filter . ']' => $this->exampleFilterValue($filter)],
                $headers
            );
        }

        foreach ($modelMeta['allowedSorts'] as $sort) {
            $requests[] = $this->requestItem('Sort by ' . $sort . ' (asc)', 'GET', $basePath, ['sort' => $sort], $headers);
            $requests[] = $this->requestItem('Sort by ' . $sort . ' (desc)', 'GET', $basePath, ['sort' => '-' . $sort], $headers);
        }

        foreach ($modelMeta['allowedIncludes'] as $include) {
            $requests[] = $this->requestItem('Include ' . $include, 'GET', $basePath, ['include' => $include], $headers);
        }
        if (count($modelMeta['allowedIncludes']) > 1) {
            $requests[] = $this->requestItem(
                'Include all',
                'GET',
                $basePath,
                ['include' => implode(',', $modelMeta['allowedIncludes'])],
                $headers
            );
        }

        if (! empty($modelMeta['allowedFields'])) {
            $requests[] = $this->requestItem(
                'Select fields',
                'GET',
                $basePath,
                ['fields[' . $slug . ']' => implode(',', array_slice($modelMeta['allowedFields'], 0, 5))],
                $headers
            );
        }

        if (! empty($modelMeta['allowedSearch'])) {
            $requests[] = $this->requestItem('Search', 'GET', $basePath, ['search' => 'example'], $headers);
        }

        foreach ($modelMeta['allowedScopes'] ?? [] as $scope) {
            $requests[] = $this->requestItem('Scope ' . $scope, 'GET', $basePath, ['scope' => $scope], $headers);
        }

        $requests[] = $this->requestItem('Paginate', 'GET', $basePath, ['per_page' => '5', 'page' => '1'], $headers);

        $combinedParams = [];
        if (! empty($modelMeta['allowedFilters'])) {
            $f = $modelMeta['allowedFilters'][0];
            $combinedParams['filter[' . $f . ']'] = $this->exampleFilterValue($f);
        }
        if (! empty($modelMeta['allowedSorts'])) {
            $combinedParams['sort'] = '-' . ($modelMeta['defaultSort'] ?? $modelMeta['allowedSorts'][0]);
        }
        if (! empty($modelMeta['allowedIncludes'])) {
            $combinedParams['include'] = implode(',', array_slice($modelMeta['allowedIncludes'], 0, 2));
        }
        if (! empty($modelMeta['allowedFields'])) {
            $combinedParams['fields[' . $slug . ']'] = implode(',', array_slice($modelMeta['allowedFields'], 0, 3));
        }
        $combinedParams['per_page'] = '10';
        $combinedParams['page'] = '1';
        $requests[] = $this->requestItem('Combined', 'GET', $basePath, $combinedParams, $headers);

        return $requests;
    }

    private function buildShowRequests(string $basePath, string $slug, array $modelMeta): array
    {
        $path = $basePath . '/{{modelId}}';
        $headers = $this->defaultHeaders();
        $requests = [
            $this->requestItem('Show by ID', 'GET', $path, [], $headers),
        ];
        if (! empty($modelMeta['allowedIncludes'])) {
            $requests[] = $this->requestItem(
                'Show with include',
                'GET',
                $path,
                ['include' => $modelMeta['allowedIncludes'][0]],
                $headers
            );
        }
        if (! empty($modelMeta['allowedFields'])) {
            $requests[] = $this->requestItem(
                'Show with fields',
                'GET',
                $path,
                ['fields[' . $slug . ']' => implode(',', array_slice($modelMeta['allowedFields'], 0, 5))],
                $headers
            );
        }
        return $requests;
    }

    private function buildStoreBodies(string $modelClass, array $modelMeta): array
    {
        $rulesStore = $modelMeta['validationRulesStore'];
        $baseRules = $modelMeta['validationRules'];
        $roleFields = $this->resolveRoleFields($rulesStore, '*');
        if ($roleFields === null || $roleFields === []) {
            $roleFields = is_array($rulesStore) && ! empty($rulesStore) && is_array(reset($rulesStore))
                ? ($rulesStore['*'] ?? $rulesStore[array_key_first($rulesStore)] ?? [])
                : (is_array($rulesStore) ? array_flip($rulesStore) : []);
        }
        return $this->exampleBodyFromRules($roleFields, $baseRules);
    }

    private function buildStoreRequests(string $basePath, string $slug, array $bodies): array
    {
        $headers = $this->defaultHeaders();
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        return [
            $this->requestItem('Create', 'POST', $basePath, [], $headers, $bodies),
        ];
    }

    private function buildUpdateBodies(string $modelClass, array $modelMeta): array
    {
        $rulesUpdate = $modelMeta['validationRulesUpdate'];
        $baseRules = $modelMeta['validationRules'];
        $roleFields = $this->resolveRoleFields($rulesUpdate, '*');
        if ($roleFields === null || $roleFields === []) {
            $roleFields = is_array($rulesUpdate) && ! empty($rulesUpdate) && is_array(reset($rulesUpdate))
                ? ($rulesUpdate['*'] ?? $rulesUpdate[array_key_first($rulesUpdate)] ?? [])
                : (is_array($rulesUpdate) ? array_flip($rulesUpdate) : []);
        }
        $full = $this->exampleBodyFromRules($roleFields, $baseRules);
        $partial = [];
        if (! empty($full)) {
            $firstKey = array_key_first($full);
            $partial = [$firstKey => $full[$firstKey]];
        }
        return ['full' => $full, 'partial' => $partial];
    }

    private function buildUpdateRequests(string $basePath, string $slug, array $bodies): array
    {
        $path = $basePath . '/{{modelId}}';
        $headers = $this->defaultHeaders();
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $requests = [];
        if (! empty($bodies['full'])) {
            $requests[] = $this->requestItem('Update all fields', 'PUT', $path, [], $headers, $bodies['full']);
        }
        if (! empty($bodies['partial'])) {
            $requests[] = $this->requestItem('Update partial', 'PUT', $path, [], $headers, $bodies['partial']);
        }
        if (empty($requests)) {
            $requests[] = $this->requestItem('Update', 'PUT', $path, [], $headers, []);
        }
        return $requests;
    }

    private function buildDestroyRequests(string $basePath, string $slug): array
    {
        $path = $basePath . '/{{modelId}}';
        return [$this->requestItem('Delete by ID', 'DELETE', $path, [], $this->defaultHeaders())];
    }

    private function buildTrashedRequests(string $basePath, string $slug, array $modelMeta): array
    {
        $path = $basePath . '/trashed';
        $headers = $this->defaultHeaders();
        $requests = [
            $this->requestItem('List trashed', 'GET', $path, [], $headers),
        ];
        if (! empty($modelMeta['allowedSorts'])) {
            $requests[] = $this->requestItem('List trashed with sort', 'GET', $path, ['sort' => '-deleted_at'], $headers);
        }
        return $requests;
    }

    private function buildRestoreRequests(string $basePath, string $slug): array
    {
        $path = $basePath . '/{{modelId}}/restore';
        return [$this->requestItem('Restore by ID', 'POST', $path, [], $this->defaultHeaders())];
    }

    private function buildForceDeleteRequests(string $basePath, string $slug): array
    {
        $path = $basePath . '/{{modelId}}/force-delete';
        return [$this->requestItem('Force delete by ID', 'DELETE', $path, [], $this->defaultHeaders())];
    }

    private function resolveRoleFields(array $roleKeyedConfig, string $roleSlug): ?array
    {
        if (! is_array($roleKeyedConfig) || empty($roleKeyedConfig)) {
            return null;
        }
        $first = reset($roleKeyedConfig);
        if (is_string($first)) {
            return null;
        }
        if (isset($roleKeyedConfig[$roleSlug]) && is_array($roleKeyedConfig[$roleSlug])) {
            return $roleKeyedConfig[$roleSlug];
        }
        if (isset($roleKeyedConfig['*'])) {
            return $roleKeyedConfig['*'];
        }
        return $roleKeyedConfig[array_key_first($roleKeyedConfig)] ?? null;
    }

    private function exampleBodyFromRules(array $roleFields, array $baseRules): array
    {
        $body = [];
        foreach ($roleFields as $field => $presence) {
            $rule = $baseRules[$field] ?? $presence;
            $body[$field] = $this->exampleValueForRule($field, is_string($rule) ? $rule : 'string');
        }
        return $body;
    }

    private function exampleValueForRule(string $field, string $rule): mixed
    {
        if (stripos($rule, 'boolean') !== false) {
            return true;
        }
        if (stripos($rule, 'integer') !== false || stripos($rule, 'exists:') !== false || stripos($rule, 'numeric') !== false) {
            return 1;
        }
        if (stripos($rule, 'max:') !== false && preg_match('/max:(\d+)/', $rule, $m)) {
            return str_repeat('a', min(10, (int) $m[1]));
        }
        return 'Example ' . $field;
    }

    private function exampleFilterValue(string $filter): string
    {
        if (in_array(strtolower($filter), ['is_published', 'is_active', 'published'], true)) {
            return '1';
        }
        return 'example';
    }

    private function defaultHeaders(): array
    {
        return [
            ['key' => 'Accept', 'value' => 'application/json'],
            ['key' => 'Authorization', 'value' => 'Bearer {{token}}'],
        ];
    }

    private function requestItem(string $name, string $method, string $path, array $queryParams, array $headers, $body = null, ?string $testScript = null): array
    {
        $query = [];
        foreach ($queryParams as $key => $value) {
            $query[] = ['key' => $key, 'value' => $value];
        }
        $raw = $path;
        if (! empty($query)) {
            $raw .= '?' . implode('&', array_map(fn ($q) => $q['key'] . '=' . rawurlencode($q['value']), $query));
        }
        $parts = array_filter(explode('/', $path), fn ($s) => $s !== '');
        $url = [
            'raw' => $raw,
            'host' => [array_shift($parts) ?? '{{baseUrl}}'],
            'path' => array_values($parts),
        ];
        if (! empty($query)) {
            $url['query'] = $query;
        }
        $request = [
            'method' => $method,
            'header' => $headers,
            'url' => $url,
        ];
        if ($body !== null && $body !== [] && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $request['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'options' => ['raw' => ['language' => 'json']],
            ];
        }
        $item = ['name' => $name];
        if ($testScript !== null && $testScript !== '') {
            $item['event'] = [
                [
                    'listen' => 'test',
                    'script' => [
                        'exec' => explode("\n", $testScript),
                        'type' => 'text/javascript',
                        'packages' => new \stdClass,
                        'requests' => new \stdClass,
                    ],
                ],
            ];
            $item['response'] = [];
        }
        $item['request'] = $request;
        return $item;
    }
}
