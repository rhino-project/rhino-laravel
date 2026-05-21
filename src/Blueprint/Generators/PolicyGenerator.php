<?php

namespace Rhino\Blueprint\Generators;

use Illuminate\Support\Str;

class PolicyGenerator
{
    /**
     * Generate a policy file content from a parsed blueprint.
     *
     * @param array $blueprint Parsed blueprint from BlueprintParser::parseModel()
     * @return string Generated PHP policy file content
     */
    public function generate(array $blueprint): string
    {
        $modelName = $blueprint['model'];
        $policyName = $modelName . 'Policy';
        $permissions = $blueprint['permissions'] ?? [];

        $permittedForShow = $this->buildPermittedAttributesMethod(
            'permittedAttributesForShow',
            $permissions,
            'show_fields'
        );

        $hiddenForShow = $this->buildHiddenAttributesMethod($permissions);

        $permittedForCreate = $this->buildPermittedAttributesMethod(
            'permittedAttributesForCreate',
            $permissions,
            'create_fields'
        );

        $permittedForUpdate = $this->buildPermittedAttributesMethod(
            'permittedAttributesForUpdate',
            $permissions,
            'update_fields'
        );

        $stub = $this->getStub();

        return $this->replacePlaceholders($stub, [
            'modelName' => $modelName,
            'policyName' => $policyName,
            'permittedForShow' => $permittedForShow,
            'hiddenForShow' => $hiddenForShow,
            'permittedForCreate' => $permittedForCreate,
            'permittedForUpdate' => $permittedForUpdate,
        ]);
    }

    /**
     * Build a permittedAttributesFor* method body.
     * Groups roles with identical field sets into a single if-branch.
     */
    public function buildPermittedAttributesMethod(
        string $methodName,
        array $permissions,
        string $fieldKey
    ): string {
        if (empty($permissions)) {
            return $this->wrapMethod($methodName, "        return ['*'];\n");
        }

        // Group roles by their field set
        $groups = $this->groupRolesByFields($permissions, $fieldKey);

        $body = '';
        $first = true;

        foreach ($groups as $group) {
            $fields = $group['fields'];
            $roles = $group['roles'];

            // Skip roles with empty field lists (no access for this surface)
            if (empty($fields)) {
                continue;
            }

            $condition = $this->buildRoleCondition($roles);

            if ($first) {
                $body .= "        if ({$condition}) {\n";
                $first = false;
            } else {
                $body .= "\n        if ({$condition}) {\n";
            }

            $body .= "            return " . $this->fieldsToPhpArray($fields) . ";\n";
            $body .= "        }\n";
        }

        // Default return
        $body .= "\n        return [];\n";

        return $this->wrapMethod($methodName, $body);
    }

    /**
     * Build the hiddenAttributesForShow method body.
     */
    public function buildHiddenAttributesMethod(array $permissions): string
    {
        // Collect only roles that have non-empty hidden_fields
        $rolesWithHidden = [];
        foreach ($permissions as $role => $definition) {
            $hiddenFields = $definition['hidden_fields'] ?? [];
            if (!empty($hiddenFields)) {
                $rolesWithHidden[$role] = $hiddenFields;
            }
        }

        if (empty($rolesWithHidden)) {
            return $this->wrapMethod('hiddenAttributesForShow', "        return [];\n");
        }

        // Group roles by their hidden fields
        $groups = [];
        foreach ($rolesWithHidden as $role => $fields) {
            $key = implode(',', $fields);
            if (!isset($groups[$key])) {
                $groups[$key] = ['fields' => $fields, 'roles' => []];
            }
            $groups[$key]['roles'][] = $role;
        }

        $body = '';
        $first = true;

        foreach ($groups as $group) {
            $condition = $this->buildRoleCondition($group['roles']);

            if ($first) {
                $body .= "        if ({$condition}) {\n";
                $first = false;
            } else {
                $body .= "\n        if ({$condition}) {\n";
            }

            $body .= "            return " . $this->fieldsToPhpArray($group['fields']) . ";\n";
            $body .= "        }\n";
        }

        $body .= "\n        return [];\n";

        return $this->wrapMethod('hiddenAttributesForShow', $body);
    }

    /**
     * Group roles that have identical field sets for a given field key.
     *
     * @return array<array{fields: array, roles: string[]}>
     */
    public function groupRolesByFields(array $permissions, string $fieldKey): array
    {
        $groups = [];

        foreach ($permissions as $role => $definition) {
            $fields = $definition[$fieldKey] ?? [];
            $key = is_array($fields) ? implode(',', $fields) : (string) $fields;

            if (!isset($groups[$key])) {
                $groups[$key] = ['fields' => $fields, 'roles' => []];
            }
            $groups[$key]['roles'][] = $role;
        }

        return array_values($groups);
    }

    /**
     * Build a role condition for an if-statement.
     * Single role: $this->hasRole($user, 'admin')
     * Multiple roles: $this->hasRole($user, 'admin') || $this->hasRole($user, 'manager')
     */
    public function buildRoleCondition(array $roles): string
    {
        $conditions = array_map(
            fn($role) => "\$this->hasRole(\$user, '{$role}')",
            $roles
        );

        if (count($conditions) === 1) {
            return $conditions[0];
        }

        // Multi-line for readability when 3+ roles
        if (count($conditions) >= 3) {
            return implode("\n            || ", $conditions);
        }

        return implode(' || ', $conditions);
    }

    /**
     * Convert a fields array to a PHP array string.
     * ['*'] becomes ['*']
     * ['id', 'title'] becomes ['id', 'title', ...]
     */
    public function fieldsToPhpArray(array $fields): string
    {
        if ($fields === ['*']) {
            return "['*']";
        }

        if (empty($fields)) {
            return '[]';
        }

        $items = array_map(fn($f) => "'{$f}'", $fields);

        // Single line if short enough
        $inline = '[' . implode(', ', $items) . ']';
        if (strlen($inline) <= 80) {
            return $inline;
        }

        // Multi-line for long lists
        $lines = implode(",\n                ", $items);
        return "[\n                {$lines},\n            ]";
    }

    /**
     * Wrap method body in a full method declaration.
     */
    protected function wrapMethod(string $methodName, string $body): string
    {
        return "    public function {$methodName}(?\\Illuminate\\Contracts\\Auth\\Authenticatable \$user): array\n    {\n{$body}    }";
    }

    /**
     * Get the policy stub template.
     */
    protected function getStub(): string
    {
        $stubPath = __DIR__ . '/../../../stubs/blueprint/policy.php.stub';

        if (!file_exists($stubPath)) {
            // Fallback: generate without stub
            return $this->getInlineStub();
        }

        return file_get_contents($stubPath);
    }

    /**
     * Inline stub as fallback.
     */
    protected function getInlineStub(): string
    {
        return <<<'STUB'
<?php

namespace App\Policies;

use App\Models\{{ modelName }};
use Rhino\Policies\ResourcePolicy;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy for the {{ modelName }} resource.
 *
 * Generated by Rhino Blueprint — zero-token deterministic generation.
 * To regenerate, modify the blueprint YAML and run: php artisan rhino:blueprint
 */
class {{ policyName }} extends ResourcePolicy
{
    // ---------------------------------------------------------------
    // Attribute Permissions (generated from blueprint)
    // ---------------------------------------------------------------

{{ permittedForShow }}

{{ hiddenForShow }}

{{ permittedForCreate }}

{{ permittedForUpdate }}
}
STUB;
    }

    /**
     * Replace placeholders in a stub.
     */
    protected function replacePlaceholders(string $stub, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $stub = str_replace("{{ {$key} }}", $value, $stub);
        }

        return $stub;
    }
}
