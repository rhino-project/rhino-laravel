<?php

namespace Rhino\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Rhino\Contracts\HasRoleBasedValidation;
use Rhino\Support\TenantExistsRules;

trait HasValidation
{
    /**
     * @deprecated 4.10.0 Model-level validation is superseded by request
     *             classes ({Model}StoreRequest / {Model}UpdateRequest, see
     *             \Rhino\Http\Requests\ResourceRequest). It keeps working
     *             unchanged throughout 4.x and is removed in 5.0.
     */
    public function validateStore(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $request->all(),
            $this->scopeExistsRulesToOrganization($this->getValidationRulesStore()),
            $this->getValidationRulesMessages()
        );
    }

    /**
     * @deprecated 4.10.0 Superseded by {Model}UpdateRequest — see validateStore().
     */
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
     *
     * @deprecated 4.10.0 Superseded by request classes
     *             ({Model}StoreRequest / {Model}UpdateRequest). A model with a
     *             request class for the action never reaches this method. Kept
     *             unchanged throughout 4.x, removed in 5.0.
     */
    public function validateForAction(Request $request, array $permittedFields, string $action, array $excludeFields = []): \Illuminate\Validation\Validator
    {
        if (! property_exists($this, 'validationRules')) {
            return Validator::make($request->all(), [], $this->getValidationRulesMessages());
        }

        $rules = $this->validationRules;

        // Fields whose values are resolved server-side later (e.g. nested "$0.id"
        // references) are excluded from format validation entirely.
        if ($excludeFields !== []) {
            $rules = array_diff_key($rules, array_flip($excludeFields));
        }

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
    // The implementation lives in Rhino\Support\TenantExistsRules so that the
    // request classes (Rhino\Http\Requests\ResourceRequest) apply exactly the
    // same scoping — including the indirect FK-chain walk — to the rules they
    // declare. The methods below are thin delegates kept for the existing call
    // sites and their tests.
    // ------------------------------------------------------------------

    /**
     * In tenant context, scope any `exists:` rules targeting org-scoped tables
     * so that the referenced record must belong to the current organization.
     *
     * @see \Rhino\Support\TenantExistsRules::scope()
     */
    private function scopeExistsRulesToOrganization(array $rules): array
    {
        return TenantExistsRules::scope($rules, request()->attributes->get('organization'));
    }

    /**
     * @see \Rhino\Support\TenantExistsRules::scopeInRuleSet()
     */
    private function scopeExistsInRuleSet(string|array $ruleSet, int|string $orgId): string|array
    {
        return TenantExistsRules::scopeInRuleSet($ruleSet, $orgId);
    }

    /**
     * @see \Rhino\Support\TenantExistsRules::walkFkChain()
     */
    private function walkFkChain(string $table, int $maxDepth, array $visited): ?array
    {
        return TenantExistsRules::walkFkChain($table, $maxDepth, $visited);
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
     *
     * @deprecated 4.10.0 Part of the model-level validation path that request
     *             classes supersede. Removed in 5.0.
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
