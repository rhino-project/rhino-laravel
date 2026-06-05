<?php

namespace Rhino\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Rhino\Contracts\HasRoleBasedValidation;

trait HasValidation
{
    public function validateStore(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $request->all(),
            $this->scopeExistsRulesToOrganization($this->getValidationRulesStore()),
            $this->getValidationRulesMessages()
        );
    }

    public function validateUpdate(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $request->all(),
            $this->scopeExistsRulesToOrganization($this->getValidationRulesUpdate()),
            $this->getValidationRulesMessages()
        );
    }

    private function getValidationRulesStore(): array
    {
        if (! property_exists($this, 'validationRules')
            || ! property_exists($this, 'validationRulesStore')) {
            return [];
        }

        $config = $this->validationRulesStore;

        if ($this->isLegacyRulesFormat($config)) {
            return array_intersect_key($this->validationRules, array_flip($config));
        }

        $roleFields = $this->resolveFieldsForRole($config, 'store');
        if ($roleFields === null || $roleFields === []) {
            return [];
        }

        return $this->mergeRulesWithPresence($roleFields, $this->validationRules);
    }

    private function getValidationRulesUpdate(): array
    {
        if (! property_exists($this, 'validationRules')
            || ! property_exists($this, 'validationRulesUpdate')) {
            return [];
        }

        $config = $this->validationRulesUpdate;

        if ($this->isLegacyRulesFormat($config)) {
            return array_intersect_key($this->validationRules, array_flip($config));
        }

        $roleFields = $this->resolveFieldsForRole($config, 'update');
        if ($roleFields === null || $roleFields === []) {
            return [];
        }

        return $this->mergeRulesWithPresence($roleFields, $this->validationRules);
    }

    /**
     * Legacy format: flat array of field names, e.g. ['title', 'content'].
     * Role-keyed format: associative array keyed by role slug, e.g. ['admin' => [...], '*' => [...]].
     */
    private function isLegacyRulesFormat(array $config): bool
    {
        if ($config === []) {
            return true;
        }

        $first = reset($config);

        return is_string($first);
    }

    /**
     * Resolve the field => presence (or full rule) array for the current user's role.
     * Returns null when not role-keyed or no config; returns empty array when role has no fields.
     */
    private function resolveFieldsForRole(array $roleKeyedConfig, string $action): ?array
    {
        $user = null;
        try {
            $user = auth('sanctum')->user();
        } catch (\InvalidArgumentException $e) {
            $user = auth()->user();
        }

        $organization = request()->attributes->get('organization');

        $roleSlug = null;
        if ($user instanceof HasRoleBasedValidation) {
            $roleSlug = $user->getRoleSlugForValidation($organization);
        }

        if ($roleSlug !== null && isset($roleKeyedConfig[$roleSlug])) {
            return $roleKeyedConfig[$roleSlug];
        }

        if (isset($roleKeyedConfig['*'])) {
            return $roleKeyedConfig['*'];
        }

        return [];
    }

    /**
     * Merge role field config (field => 'required'|'nullable'|'sometimes'|full rule) with base format rules.
     * If the modifier contains '|', it is treated as a full rule override; otherwise prepended to base.
     */
    private function mergeRulesWithPresence(array $roleFields, array $baseRules): array
    {
        $merged = [];

        foreach ($roleFields as $field => $modifier) {
            $modifier = (string) $modifier;

            if (str_contains($modifier, '|')) {
                $merged[$field] = $modifier;
                continue;
            }

            $base = $baseRules[$field] ?? '';
            $merged[$field] = $base !== '' ? $modifier.'|'.$base : $modifier;
        }

        return $merged;
    }

    private function getValidationRulesMessages(): array
    {
        return property_exists($this, 'validationRulesMessages') ? $this->validationRulesMessages : [];
    }

    // ------------------------------------------------------------------
    // New policy-driven validation (used by GlobalController)
    // ------------------------------------------------------------------

    /**
     * Validate request data for a given action using only $validationRules (format rules).
     *
     * Unlike validateStore/validateUpdate which also handle field allowlisting via
     * $validationRulesStore/$validationRulesUpdate, this method validates only the
     * format rules for the given permitted fields. Field permission checking is
     * handled separately by the Policy.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array<string>  $permittedFields  Fields the user is allowed to send (['*'] = all)
     * @param  string  $action  'store' or 'update'
     * @return \Illuminate\Validation\Validator
     */
    public function validateForAction(Request $request, array $permittedFields, string $action): \Illuminate\Validation\Validator
    {
        if (! property_exists($this, 'validationRules')) {
            return Validator::make($request->all(), [], $this->getValidationRulesMessages());
        }

        $rules = $this->validationRules;

        // If permittedFields is not wildcard, only validate those fields
        if ($permittedFields !== ['*']) {
            $rules = array_intersect_key($rules, array_flip($permittedFields));
        }

        // Partial update (PATCH/PUT) semantics: on update, only validate fields
        // actually present in the request, so sending e.g. {"status":"done"} is
        // not rejected for omitting other 'required' fields. Store still
        // validates the full ruleset (required fields are enforced on create).
        if ($action === 'update') {
            $rules = array_intersect_key($rules, $request->all());
        }

        $rules = $this->scopeExistsRulesToOrganization($rules);

        return Validator::make($request->all(), $rules, $this->getValidationRulesMessages());
    }

    // ------------------------------------------------------------------
    // Cross-tenant exists: rule scoping
    // ------------------------------------------------------------------

    private static array $orgColumnCache = [];
    private static array $fkChainCache = [];

    /**
     * In tenant context, scope any `exists:` rules targeting org-scoped tables
     * so that the referenced record must belong to the current organization.
     *
     * Direct: "exists:blogs,id" → "exists:blogs,id,organization_id,3"
     * Indirect: "exists:blog_posts,id" → Rule::exists with subquery through FK chain
     */
    private function scopeExistsRulesToOrganization(array $rules): array
    {
        $organization = request()->attributes->get('organization');
        if (!$organization) {
            return $rules;
        }

        // In tenant context, organization_id is managed by the framework — remove from validation.
        unset($rules['organization_id']);

        $orgId = $organization->id;

        foreach ($rules as $field => &$ruleSet) {
            $ruleSet = $this->scopeExistsInRuleSet($ruleSet, $orgId);
        }

        return $rules;
    }

    /**
     * Process a rule set (string or array) and scope any exists: rules to the current org.
     * Returns string if no Rule objects were needed, array otherwise.
     */
    private function scopeExistsInRuleSet(string|array $ruleSet, int|string $orgId): string|array
    {
        if (is_string($ruleSet)) {
            $parts = explode('|', $ruleSet);
        } else {
            $parts = $ruleSet;
        }

        $needsObjectRule = false;
        $result = [];

        foreach ($parts as $part) {
            if (!is_string($part) || !str_starts_with($part, 'exists:')) {
                $result[] = $part;
                continue;
            }

            $params = substr($part, 7); // strip "exists:"
            $segments = explode(',', $params);
            $table = $segments[0] ?? null;
            $column = $segments[1] ?? 'id';

            if (!$table) {
                $result[] = $part;
                continue;
            }

            // Skip if already scoped
            if (in_array('organization_id', $segments)) {
                $result[] = $part;
                continue;
            }

            // Direct: table has organization_id — simple string append
            if ($this->tableHasOrganizationId($table)) {
                $result[] = $part . ',organization_id,' . $orgId;
                continue;
            }

            // Indirect: walk FK chain to find org-scoped ancestor
            $chain = $this->findOrganizationFkChain($table);
            if ($chain !== null) {
                $needsObjectRule = true;
                $result[] = $this->buildScopedExistsRule($table, $column, $orgId, $chain);
                continue;
            }

            // No org scoping possible — leave unchanged
            $result[] = $part;
        }

        // If no Rule objects were introduced and original was a string, return string
        if (!$needsObjectRule && is_string($ruleSet)) {
            return implode('|', $result);
        }

        return $result;
    }

    /**
     * Find the FK chain from a table to an org-scoped ancestor table.
     * Returns an array of steps, or null if no chain exists.
     *
     * Each step: ['local_column' => ..., 'foreign_table' => ..., 'foreign_column' => ...]
     */
    private function findOrganizationFkChain(string $table): ?array
    {
        if (array_key_exists($table, self::$fkChainCache)) {
            return self::$fkChainCache[$table];
        }

        $chain = $this->walkFkChain($table, 5, []);
        self::$fkChainCache[$table] = $chain;

        return $chain;
    }

    private function walkFkChain(string $table, int $maxDepth, array $visited): ?array
    {
        if ($maxDepth <= 0 || in_array($table, $visited)) {
            return null;
        }

        $visited[] = $table;

        try {
            $foreignKeys = Schema::getForeignKeys($table);
        } catch (\Exception $e) {
            return null;
        }

        foreach ($foreignKeys as $fk) {
            $localColumn = $fk['columns'][0];
            $foreignTable = $fk['foreign_table'];
            $foreignColumn = $fk['foreign_columns'][0];

            if ($this->tableHasOrganizationId($foreignTable)) {
                return [['local_column' => $localColumn, 'foreign_table' => $foreignTable, 'foreign_column' => $foreignColumn]];
            }

            $deeper = $this->walkFkChain($foreignTable, $maxDepth - 1, $visited);
            if ($deeper !== null) {
                array_unshift($deeper, ['local_column' => $localColumn, 'foreign_table' => $foreignTable, 'foreign_column' => $foreignColumn]);
                return $deeper;
            }
        }

        return null;
    }

    /**
     * Build a Rule::exists() with nested whereIn subqueries through the FK chain.
     */
    private function buildScopedExistsRule(string $table, string $column, int|string $orgId, array $chain): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, $column)->where(function ($query) use ($orgId, $chain) {
            $this->applyFkChainScope($query, $orgId, $chain, 0);
        });
    }

    private function applyFkChainScope($query, int|string $orgId, array $chain, int $index): void
    {
        $step = $chain[$index];
        $localCol = $step['local_column'];
        $foreignTable = $step['foreign_table'];
        $foreignCol = $step['foreign_column'];

        if ($index === count($chain) - 1) {
            // Last step — the foreign table has organization_id
            $query->whereIn($localCol, function ($sub) use ($foreignTable, $foreignCol, $orgId) {
                $sub->select($foreignCol)->from($foreignTable)->where('organization_id', $orgId);
            });
        } else {
            // Intermediate step — recurse deeper
            $query->whereIn($localCol, function ($sub) use ($foreignTable, $foreignCol, $orgId, $chain, $index) {
                $sub->select($foreignCol)->from($foreignTable);
                $this->applyFkChainScope($sub, $orgId, $chain, $index + 1);
            });
        }
    }

    private function tableHasOrganizationId(string $table): bool
    {
        if (!isset(self::$orgColumnCache[$table])) {
            self::$orgColumnCache[$table] = Schema::hasColumn($table, 'organization_id');
        }

        return self::$orgColumnCache[$table];
    }

    /**
     * Find fields in the request that are not in the permitted list.
     *
     * Returns an array of field names that the user sent but is not allowed to.
     * Returns an empty array if all fields are permitted or permittedFields is ['*'].
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array<string>  $permittedFields  Fields the user is allowed to send (['*'] = all)
     * @return array<string>  Forbidden field names
     */
    public function findForbiddenFields(Request $request, array $permittedFields): array
    {
        if ($permittedFields === ['*']) {
            return [];
        }

        $requestFields = array_keys($request->all());

        return array_values(array_diff($requestFields, $permittedFields));
    }

    /**
     * Check if this model uses the legacy validation rules config.
     *
     * Returns true when the model has non-empty $validationRulesStore or
     * $validationRulesUpdate, indicating it uses the old model-level field
     * allowlisting. When true, the controller should use the legacy
     * validateStore/validateUpdate flow instead of the new policy-driven flow.
     *
     * @return bool
     */
    public function hasLegacyRulesConfig(): bool
    {
        $hasStore = property_exists($this, 'validationRulesStore')
            && !empty($this->validationRulesStore);

        $hasUpdate = property_exists($this, 'validationRulesUpdate')
            && !empty($this->validationRulesUpdate);

        return $hasStore || $hasUpdate;
    }
}
