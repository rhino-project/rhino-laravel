<?php

namespace Rhino\Blueprint\Generators;

use Illuminate\Support\Str;

class TestGenerator
{
    /**
     * Generate test file content from a parsed blueprint.
     *
     * @param array $blueprint Parsed blueprint
     * @param string $framework 'pest' or 'phpunit'
     * @param bool $isMultiTenant Whether the app uses multi-tenant infrastructure (Organization, Role, UserRole)
     * @param string $orgIdentifier Organization identifier column (e.g., 'slug', 'id')
     * @return string Generated test file content
     */
    public function generate(
        array $blueprint,
        string $framework = 'pest',
        bool $isMultiTenant = false,
        string $orgIdentifier = 'slug'
    ): string {
        $modelName = $blueprint['model'];
        $slug = $blueprint['slug'];
        $permissions = $blueprint['permissions'] ?? [];

        $roleTests = $this->buildCrudAccessTests($modelName, $slug, $permissions, $isMultiTenant, $orgIdentifier, $framework);
        $fieldVisibilityTests = $this->buildFieldVisibilityTests($modelName, $slug, $permissions, $isMultiTenant, $orgIdentifier, $framework);
        $forbiddenFieldTests = $this->buildForbiddenFieldTests($modelName, $slug, $permissions, $isMultiTenant, $orgIdentifier, $framework);

        $allTests = $roleTests . $fieldVisibilityTests . $forbiddenFieldTests;

        if ($framework === 'phpunit') {
            return $isMultiTenant
                ? $this->wrapPhpUnit($modelName, $allTests)
                : $this->wrapPhpUnitNonTenant($modelName, $allTests);
        }

        return $isMultiTenant
            ? $this->wrapPest($modelName, $allTests)
            : $this->wrapPestNonTenant($modelName, $allTests);
    }

    /**
     * Build CRUD access tests (200 for allowed, 403 for blocked).
     */
    public function buildCrudAccessTests(
        string $modelName,
        string $slug,
        array $permissions,
        bool $isMultiTenant,
        string $orgIdentifier,
        string $framework
    ): string {
        if (empty($permissions)) {
            return '';
        }

        $isPest = $framework === 'pest';
        $indent = $isPest ? '    ' : '        ';

        $tests = $isPest
            ? "// ---------------------------------------------------------------\n// Role-based CRUD access tests\n// ---------------------------------------------------------------\n\n"
            : "    // ---------------------------------------------------------------\n    // Role-based CRUD access tests\n    // ---------------------------------------------------------------\n\n";

        // Build URLs
        if ($isMultiTenant) {
            $listUrl = "'/api/' . \$org->{$orgIdentifier} . '/{$slug}'";
            $itemUrl = "'/api/' . \$org->{$orgIdentifier} . '/{$slug}/' . \$model->id";
        } else {
            $listUrl = "'/api/{$slug}'";
            $itemUrl = "'/api/{$slug}/' . \$model->id";
        }

        foreach ($permissions as $role => $definition) {
            $actions = $definition['actions'] ?? [];
            $permStrings = $this->actionsToPermissions($slug, $actions);
            $permissionsPhp = $this->toPhpArray($permStrings);

            $allActions = ['index', 'show', 'store', 'update', 'destroy'];
            $allowedEndpoints = array_intersect($allActions, $actions);
            $blockedEndpoints = array_diff($allActions, $actions);

            // Allowed endpoints test
            if (!empty($allowedEndpoints)) {
                $tests .= $this->buildAccessTest(
                    $modelName, $slug, $role, $permissionsPhp,
                    $allowedEndpoints, true,
                    $isMultiTenant, $listUrl, $itemUrl,
                    $isPest, $indent
                );
            }

            // Blocked endpoints test
            if (!empty($blockedEndpoints)) {
                $tests .= $this->buildAccessTest(
                    $modelName, $slug, $role, $permissionsPhp,
                    $blockedEndpoints, false,
                    $isMultiTenant, $listUrl, $itemUrl,
                    $isPest, $indent
                );
            }
        }

        return $tests;
    }

    /**
     * Build field visibility tests for roles with restricted show_fields.
     */
    public function buildFieldVisibilityTests(
        string $modelName,
        string $slug,
        array $permissions,
        bool $isMultiTenant,
        string $orgIdentifier,
        string $framework
    ): string {
        $isPest = $framework === 'pest';
        $indent = $isPest ? '    ' : '        ';
        $tests = '';

        if ($isMultiTenant) {
            $itemUrl = "'/api/' . \$org->{$orgIdentifier} . '/{$slug}/' . \$model->id";
        } else {
            $itemUrl = "'/api/{$slug}/' . \$model->id";
        }

        $hasTests = false;

        foreach ($permissions as $role => $definition) {
            $showFields = $definition['show_fields'] ?? ['*'];
            $hiddenFields = $definition['hidden_fields'] ?? [];
            $actions = $definition['actions'] ?? [];

            // Only generate visibility tests for roles that can view and have restrictions
            if (!in_array('show', $actions)) {
                continue;
            }
            if ($showFields === ['*'] && empty($hiddenFields)) {
                continue;
            }

            if (!$hasTests) {
                $tests .= $isPest
                    ? "// ---------------------------------------------------------------\n// Field visibility tests\n// ---------------------------------------------------------------\n\n"
                    : "    // ---------------------------------------------------------------\n    // Field visibility tests\n    // ---------------------------------------------------------------\n\n";
                $hasTests = true;
            }

            $permStrings = $this->actionsToPermissions($slug, $actions);
            $permissionsPhp = $this->toPhpArray($permStrings);

            if ($isPest) {
                $tests .= "it('shows only permitted fields for {$role} on {$slug}', function () {\n";
            } else {
                $methodName = 'test_' . $role . '_sees_only_permitted_fields_on_' . $slug;
                $tests .= "    public function {$methodName}(): void\n    {\n";
            }

            $tests .= $this->buildUserSetup($role, $permissionsPhp, $isMultiTenant, $isPest, $indent);

            if ($isMultiTenant) {
                $tests .= "{$indent}\$model = {$modelName}::factory()->create(['organization_id' => \$org->id]);\n\n";
            } else {
                $tests .= "{$indent}\$model = {$modelName}::factory()->create();\n\n";
            }

            $tests .= "{$indent}\$this->actingAs(\$user);\n\n";
            $tests .= "{$indent}\$response = \$this->getJson({$itemUrl});\n";
            $tests .= "{$indent}\$response->assertStatus(200);\n\n";
            $tests .= "{$indent}\$data = \$response->json();\n\n";

            // Assert visible fields
            if ($showFields !== ['*'] && !empty($showFields)) {
                $tests .= "{$indent}// Should see these fields\n";
                foreach (array_slice($showFields, 0, 5) as $field) {
                    $tests .= "{$indent}\$this->assertArrayHasKey('{$field}', \$data);\n";
                }
                if (count($showFields) > 5) {
                    $tests .= "{$indent}// ... and " . (count($showFields) - 5) . " more permitted fields\n";
                }
                $tests .= "\n";
            }

            // Assert hidden fields
            if (!empty($hiddenFields)) {
                $tests .= "{$indent}// Should NOT see these fields\n";
                foreach ($hiddenFields as $field) {
                    $tests .= "{$indent}\$this->assertArrayNotHasKey('{$field}', \$data);\n";
                }
            }

            $tests .= $isPest ? "});\n\n" : "    }\n\n";
        }

        return $tests;
    }

    /**
     * Build forbidden field tests (403 when setting restricted fields).
     */
    public function buildForbiddenFieldTests(
        string $modelName,
        string $slug,
        array $permissions,
        bool $isMultiTenant,
        string $orgIdentifier,
        string $framework
    ): string {
        $isPest = $framework === 'pest';
        $indent = $isPest ? '    ' : '        ';
        $tests = '';

        if ($isMultiTenant) {
            $listUrl = "'/api/' . \$org->{$orgIdentifier} . '/{$slug}'";
        } else {
            $listUrl = "'/api/{$slug}'";
        }

        // Find roles that have 'store' action but restricted create_fields (not wildcard)
        $hasTests = false;

        // First, find a role with wildcard create access to know ALL possible fields
        $allCreateFields = [];
        foreach ($permissions as $role => $definition) {
            $createFields = $definition['create_fields'] ?? [];
            if ($createFields === ['*']) {
                continue; // Wildcard role — skip
            }
            if (is_array($createFields)) {
                $allCreateFields = array_merge($allCreateFields, $createFields);
            }
        }
        $allCreateFields = array_unique($allCreateFields);

        foreach ($permissions as $role => $definition) {
            $actions = $definition['actions'] ?? [];
            $createFields = $definition['create_fields'] ?? [];

            // Only test roles that can store but have restricted fields
            if (!in_array('store', $actions)) {
                continue;
            }
            if ($createFields === ['*'] || empty($createFields)) {
                continue;
            }

            // Find a field this role CAN'T set but others can
            $forbiddenExamples = array_diff($allCreateFields, $createFields);
            if (empty($forbiddenExamples)) {
                continue;
            }

            if (!$hasTests) {
                $tests .= $isPest
                    ? "// ---------------------------------------------------------------\n// Forbidden field tests (403 on restricted fields)\n// ---------------------------------------------------------------\n\n"
                    : "    // ---------------------------------------------------------------\n    // Forbidden field tests (403 on restricted fields)\n    // ---------------------------------------------------------------\n\n";
                $hasTests = true;
            }

            $permStrings = $this->actionsToPermissions($slug, $actions);
            $permissionsPhp = $this->toPhpArray($permStrings);
            $forbiddenField = reset($forbiddenExamples);

            if ($isPest) {
                $tests .= "it('returns 403 when {$role} tries to set restricted fields on {$slug} create', function () {\n";
            } else {
                $methodName = 'test_' . $role . '_cannot_set_restricted_fields_on_' . $slug . '_create';
                $tests .= "    public function {$methodName}(): void\n    {\n";
            }

            $tests .= $this->buildUserSetup($role, $permissionsPhp, $isMultiTenant, $isPest, $indent);
            $tests .= "\n";

            $tests .= "{$indent}\$this->actingAs(\$user);\n\n";
            $tests .= "{$indent}\$response = \$this->postJson({$listUrl}, [\n";

            // Include one allowed field and one forbidden field
            $allowedField = reset($createFields);
            $tests .= "{$indent}    '{$allowedField}' => 'test-value',\n";
            $tests .= "{$indent}    '{$forbiddenField}' => 'forbidden-value', // restricted for {$role}\n";
            $tests .= "{$indent}]);\n\n";
            $tests .= "{$indent}\$response->assertStatus(403);\n";

            $tests .= $isPest ? "});\n\n" : "    }\n\n";
        }

        return $tests;
    }

    /**
     * Build user setup code for a test body.
     *
     * Multi-tenant: creates org, then calls createUserWithRole() with org + permissions.
     * Non-tenant: calls createUserWithPermissions() with permissions directly on user.
     */
    protected function buildUserSetup(
        string $role,
        string $permissionsPhp,
        bool $isMultiTenant,
        bool $isPest,
        string $indent
    ): string {
        $lines = '';

        if ($isMultiTenant) {
            $lines .= "{$indent}\$org = Organization::factory()->create();\n";
            $createCall = $isPest ? 'createUserWithRole' : '$this->createUserWithRole';
            $lines .= "{$indent}\$user = {$createCall}('{$role}', \$org, {$permissionsPhp});\n";
        } else {
            $createCall = $isPest ? 'createUserWithPermissions' : '$this->createUserWithPermissions';
            $lines .= "{$indent}\$user = {$createCall}({$permissionsPhp});\n";
        }

        return $lines;
    }

    /**
     * Build a single access test (allowed or blocked).
     */
    protected function buildAccessTest(
        string $modelName,
        string $slug,
        string $role,
        string $permissionsPhp,
        array $endpoints,
        bool $allowed,
        bool $isMultiTenant,
        string $listUrl,
        string $itemUrl,
        bool $isPest,
        string $indent
    ): string {
        $type = $allowed ? 'permitted' : 'restricted';
        $status = $allowed ? 200 : 403;

        $test = '';

        if ($isPest) {
            $verb = $allowed ? 'allows' : 'blocks';
            $test .= "it('{$verb} {$role} {$type} {$slug} endpoints', function () {\n";
        } else {
            $verb = $allowed ? 'can_access' : 'is_blocked_from';
            $methodName = "test_{$role}_{$verb}_{$type}_{$slug}_endpoints";
            $test .= "    public function {$methodName}(): void\n    {\n";
        }

        $test .= $this->buildUserSetup($role, $permissionsPhp, $isMultiTenant, $isPest, $indent);
        $test .= "{$indent}\$model = {$modelName}::factory()->create();\n\n";
        $test .= "{$indent}\$this->actingAs(\$user);\n\n";

        foreach ($endpoints as $endpoint) {
            $expectedStatus = $allowed ? ($endpoint === 'store' ? 201 : 200) : 403;

            $test .= match ($endpoint) {
                'index' => "{$indent}\$this->getJson({$listUrl})->assertStatus({$expectedStatus});\n",
                'show' => "{$indent}\$this->getJson({$itemUrl})->assertStatus({$expectedStatus});\n",
                'store' => $allowed
                    ? "{$indent}// \$this->postJson({$listUrl}, [...])->assertStatus({$expectedStatus});\n"
                    : "{$indent}\$this->postJson({$listUrl}, [])->assertStatus({$expectedStatus});\n",
                'update' => $allowed
                    ? "{$indent}// \$this->putJson({$itemUrl}, [...])->assertStatus({$expectedStatus});\n"
                    : "{$indent}\$this->putJson({$itemUrl}, [])->assertStatus({$expectedStatus});\n",
                'destroy' => "{$indent}\$this->deleteJson({$itemUrl})->assertStatus({$expectedStatus});\n",
                default => '',
            };
        }

        $test .= $isPest ? "});\n\n" : "    }\n\n";

        return $test;
    }

    /**
     * Convert actions to permission strings.
     */
    public function actionsToPermissions(string $slug, array $actions): array
    {
        // Check if all CRUD actions are present — use wildcard
        $allActions = ['index', 'show', 'store', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'];
        if (count(array_diff($allActions, $actions)) === 0) {
            return ["{$slug}.*"];
        }

        return array_map(fn($a) => "{$slug}.{$a}", $actions);
    }

    /**
     * Convert an array to a PHP array literal string.
     */
    public function toPhpArray(array $items): string
    {
        if (empty($items)) {
            return '[]';
        }

        $parts = array_map(fn($p) => "'{$p}'", $items);

        return '[' . implode(', ', $parts) . ']';
    }

    // ------------------------------------------------------------------
    // Multi-tenant wrappers (Organization + Role + UserRole)
    // ------------------------------------------------------------------

    /**
     * Wrap tests in Pest format (multi-tenant).
     */
    protected function wrapPest(string $modelName, string $tests): string
    {
        return <<<PHP
<?php

use App\Models\User;
use App\Models\\{$modelName};
use App\Models\Role;
use App\Models\Organization;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------
// Helper: create a user with a specific role and permissions
// ---------------------------------------------------------------

function createUserWithRole(string \$roleSlug, ?Organization \$organization = null, array \$permissions = []): User
{
    \$user = User::factory()->create();
    \$org = \$organization ?? Organization::factory()->create();
    \$role = Role::where('slug', \$roleSlug)->firstOrFail();

    UserRole::create([
        'user_id' => \$user->id,
        'role_id' => \$role->id,
        'organization_id' => \$org->id,
        'permissions' => \$permissions,
    ]);

    return \$user;
}

{$tests}
PHP;
    }

    /**
     * Wrap tests in PHPUnit format (multi-tenant).
     */
    protected function wrapPhpUnit(string $modelName, string $tests): string
    {
        return <<<PHP
<?php

namespace Tests\Model;

use App\Models\User;
use App\Models\\{$modelName};
use App\Models\Role;
use App\Models\Organization;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class {$modelName}Test extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithRole(string \$roleSlug, ?Organization \$organization = null, array \$permissions = []): User
    {
        \$user = User::factory()->create();
        \$org = \$organization ?? Organization::factory()->create();
        \$role = Role::where('slug', \$roleSlug)->firstOrFail();

        UserRole::create([
            'user_id' => \$user->id,
            'role_id' => \$role->id,
            'organization_id' => \$org->id,
            'permissions' => \$permissions,
        ]);

        return \$user;
    }

{$tests}
}

PHP;
    }

    // ------------------------------------------------------------------
    // Non-tenant wrappers (permissions stored directly on User model)
    // ------------------------------------------------------------------

    /**
     * Wrap tests in Pest format (non-tenant).
     *
     * Permissions are stored as a JSON array on the User model's `permissions` column.
     * No Role, Organization, or UserRole models are needed.
     */
    protected function wrapPestNonTenant(string $modelName, string $tests): string
    {
        return <<<PHP
<?php

use App\Models\User;
use App\Models\\{$modelName};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------
// Helper: create a user with permissions stored on the user model
// ---------------------------------------------------------------

function createUserWithPermissions(array \$permissions = []): User
{
    return User::factory()->create(['permissions' => \$permissions]);
}

{$tests}
PHP;
    }

    /**
     * Wrap tests in PHPUnit format (non-tenant).
     *
     * Permissions are stored as a JSON array on the User model's `permissions` column.
     * No Role, Organization, or UserRole models are needed.
     */
    protected function wrapPhpUnitNonTenant(string $modelName, string $tests): string
    {
        return <<<PHP
<?php

namespace Tests\Model;

use App\Models\User;
use App\Models\\{$modelName};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class {$modelName}Test extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithPermissions(array \$permissions = []): User
    {
        return User::factory()->create(['permissions' => \$permissions]);
    }

{$tests}
}

PHP;
    }
}
