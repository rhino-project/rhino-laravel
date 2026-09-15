<?php

namespace Rhino\Traits;

use Illuminate\Support\Facades\Gate;
use Rhino\Contracts\HasHiddenColumns;
use Rhino\Contracts\HasPermittedAttributes;

trait HidableColumns
{
    /**
     * Cache for dynamically resolved hidden columns per model class and user.
     * Prevents N+1 queries when serializing collections.
     *
     * @var array<string, array<string>>
     */
    private static array $hiddenColumnsCache = [];

    protected $baseHiddenColumns = [
        'password',
        'remember_token',
        'has_temporary_password',
        'updated_at',
        'created_at',
        'deleted_at',
        'email_verified_at',
    ];

    public function initializeHidableColumns()
    {
        $this->hidden = array_merge($this->hidden, $this->baseHiddenColumns);

        // Support both static and non-static $additionalHiddenColumns
        if (property_exists(static::class, 'additionalHiddenColumns')) {
            $ref = new \ReflectionProperty(static::class, 'additionalHiddenColumns');
            $additional = $ref->isStatic() ? static::$additionalHiddenColumns : $this->additionalHiddenColumns;
            if (is_array($additional)) {
                $this->hidden = array_merge($this->hidden, $additional);
            }
        }
    }

    /**
     * Get the hidden attributes for the model.
     *
     * Extends Laravel's getHidden() to support dynamic column hiding based on the
     * authenticated user. Checks the model's policy for a hiddenColumns() method
     * (via the HasHiddenColumns contract or ResourcePolicy base class).
     *
     * When called from asRhinoJson(), returns empty — filtering is handled there
     * with the explicit $user parameter instead of auth().
     *
     * Priority:
     *   1. Base hidden columns ($baseHiddenColumns) — always applied
     *   2. Static additional columns ($additionalHiddenColumns) — always applied
     *   3. Policy hiddenColumns() — contextual, based on authenticated user
     *
     * Results are cached per model class + user to avoid N+1 queries on collections.
     *
     * @return array<string>
     */
    public function getHidden()
    {
        // When called from asRhinoJson, return empty — filtering is handled there.
        if (!empty($this->asRhinoJsonActive)) {
            return [];
        }

        $hidden = $this->hidden;

        try {
            $user = auth('sanctum')->user();
        } catch (\InvalidArgumentException $e) {
            // Sanctum guard not defined — fall back to default guard
            $user = auth()->user();
        }
        $cacheKey = static::class . ':' . ($user?->id ?? 'guest') . ':' . (request()->attributes->get('organization')?->id ?? 'no-org');

        if (!isset(static::$hiddenColumnsCache[$cacheKey])) {
            static::$hiddenColumnsCache[$cacheKey] = $this->resolveHiddenColumnsFromPolicy($user);
        }

        return array_unique(array_merge($hidden, static::$hiddenColumnsCache[$cacheKey]));
    }

    /**
     * Resolve additional hidden columns from the model's policy.
     *
     * When the policy implements HasPermittedAttributes:
     *   - hiddenAttributesForShow() columns are added to the hidden list
     *   - permittedAttributesForShow() is used to filter: if not ['*'],
     *     all columns NOT in the permitted list are also hidden
     *
     * Falls back to the legacy hiddenColumns() method on HasHiddenColumns.
     *
     * @param  mixed  $user
     * @return array<string>
     */
    protected function resolveHiddenColumnsFromPolicy($user): array
    {
        try {
            $policy = Gate::getPolicyFor($this);

            if ($policy instanceof HasPermittedAttributes) {
                $hidden = $policy->hiddenAttributesForShow($user);

                // Backward compat: also call legacy hiddenColumns() if the policy
                // implements HasHiddenColumns (catches child overrides that haven't
                // migrated to hiddenAttributesForShow yet)
                if ($policy instanceof HasHiddenColumns) {
                    $hidden = array_unique(array_merge($hidden, $policy->hiddenColumns($user)));
                }

                $permitted = $policy->permittedAttributesForShow($user);
                if ($permitted !== ['*']) {
                    // Hide all columns that are NOT in the permitted list.
                    // The id and the resolved route-key column are always kept
                    // visible so clients can build member URLs even under
                    // restrictive whitelists (explicit blacklists still win).
                    $allColumns = $this->getColumns();
                    $notPermitted = array_diff($allColumns, $permitted, $this->rhinoAlwaysPermittedColumns());
                    $hidden = array_unique(array_merge($hidden, $notPermitted));
                }

                return $hidden;
            }

            // Legacy fallback: policy only implements HasHiddenColumns (not HasPermittedAttributes)
            if ($policy instanceof HasHiddenColumns) {
                return $policy->hiddenColumns($user);
            }
        } catch (\Exception $e) {
            // If policy resolution fails, fall back to no additional columns
        }

        return [];
    }

    /**
     * Serialize the model to an array, excluding hidden columns and applying
     * the policy whitelist. Computed attributes added via rhinoComputedAttributes(),
     * $appends, or accessors are fully supported — they can be hidden or whitelisted
     * just like database columns.
     *
     * Do NOT override this method. Override rhinoComputedAttributes() instead
     * to add computed/virtual attributes to the JSON response.
     *
     * This mirrors Rails' as_rhino_json and AdonisJS' serializeWithHidden,
     * providing a consistent serialization entry point across all frameworks.
     *
     * @param  mixed  $user  The authenticated user (or null for guests)
     * @param  array<string>  $requestedComputedAttributes  Opt-in record-level
     *   computed attributes to evaluate, selected via `?computed_attributes=`.
     * @param  array<string, array<int, mixed>>  $computedArguments  Positional
     *   arguments per attribute name. An attribute with required parameters and
     *   no entry here is skipped rather than called with too few arguments, so
     *   an existing direct caller that passes only names keeps working.
     * @return array<string, mixed>
     */
    public function asRhinoJson($user = null, array $requestedComputedAttributes = [], array $computedArguments = []): array
    {
        // Set flag so getHidden() returns [] — we handle filtering explicitly below.
        $this->asRhinoJsonActive = true;
        $result = $this->toArray();
        $this->asRhinoJsonActive = false;

        // Remove the internal flag from serialized output
        unset($result['asRhinoJsonActive']);

        // Merge computed attributes from model BEFORE applying policy filtering.
        $computed = $this->rhinoComputedAttributes();
        if (is_array($computed) && !empty($computed)) {
            $result = array_merge($result, $computed);
        }

        // Merge the OPT-IN record-level computed attributes the client selected
        // via ?computed_attributes=. Nothing is evaluated unless it was asked
        // for by name, so declaring an expensive attribute costs nothing on the
        // requests that don't want it. Merged before policy filtering, so the
        // blacklist/whitelist below still governs them.
        $result = array_merge($result, $this->rhinoResolveRecordComputedAttributes(
            $requestedComputedAttributes,
            $user,
            $computedArguments
        ));

        // Apply blacklist (base + additional + policy)
        $hidden = $this->resolveAllHiddenColumns($user);
        $result = array_diff_key($result, array_flip($hidden));

        // Apply whitelist (covers computed attributes too)
        $permitted = $this->resolvePolicyPermittedAttributes($user);
        if ($permitted !== null && $permitted !== ['*']) {
            $permittedSet = array_flip($permitted);
            // id and the resolved route-key column are always allowed so
            // clients can build member URLs (e.g. /api/jobs/{hash_id})
            foreach ($this->rhinoAlwaysPermittedColumns() as $column) {
                $permittedSet[$column] = true;
            }
            $result = array_intersect_key($result, $permittedSet);
        }

        return $result;
    }

    /**
     * Columns that must survive policy whitelists: the id plus the resolved
     * route-key column (see Rhino::routeKeyName()). By default the route key
     * resolves to the primary key, so this is just ['id'] — identical to the
     * historical behavior.
     *
     * @return array<string>
     */
    protected function rhinoAlwaysPermittedColumns(): array
    {
        return array_unique(['id', \Rhino\Facades\Rhino::routeKeyName($this)]);
    }

    /**
     * Override this method in your model to add computed/virtual attributes
     * to the JSON response. These attributes are merged BEFORE policy filtering,
     * so they are always subject to blacklist (hiddenAttributesForShow) and
     * whitelist (permittedAttributesForShow).
     *
     * @example
     * ```php
     * public function rhinoComputedAttributes(): array
     * {
     *     return [
     *         'full_name' => $this->first_name . ' ' . $this->last_name,
     *         'is_overdue' => $this->due_date?->isPast(),
     *     ];
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public function rhinoComputedAttributes(): array
    {
        return [];
    }

    /**
     * Override this method to declare OPT-IN record-level computed attributes.
     *
     * Unlike rhinoComputedAttributes(), nothing here is evaluated unless the
     * client names it in `?computed_attributes=a,b` on index/show/trashed —
     * so expensive per-row work is only paid for when it is actually wanted.
     *
     * Return a map of attribute name => callable($record, $user).
     *
     * An attribute may also declare PARAMETERS the client supplies as
     * `?computed_attributes[name][param]=value`. Use the extended form — a map
     * carrying `params` (and optionally `optional` and `using`) — and the bound
     * arguments are appended after $user, in declared order. Any other declared
     * value (a callable, a scalar, a plain list) keeps its current meaning.
     *
     * @example
     * ```php
     * public function rhinoRecordComputedAttributes(): array
     * {
     *     return [
     *         'open_tickets_count' => fn ($record, $user) => $record->tickets()->whereNull('closed_at')->count(),
     *         'avatar_url' => fn ($record, $user) => Storage::url($record->avatar_path),
     *         'tickets_since' => [
     *             'params'   => ['since', 'status'],
     *             'optional' => ['status'],
     *             'using'    => fn ($record, $user, $since, $status = null) =>
     *                 $record->tickets()->where('created_at', '>=', $since)->count(),
     *         ],
     *     ];
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public function rhinoRecordComputedAttributes(): array
    {
        return [];
    }

    /**
     * Override this method to declare COLLECTION-level computed attributes,
     * served by `GET /api/{resource}/computed?attributes=a,b`.
     *
     * Each callable receives the fully scoped query (organization scope, global
     * scopes, `?scope=`, `?filter[]=` and `?search=` already applied) plus the
     * current user, and is evaluated ONCE for the whole collection — not once
     * per row. This is the cheap way to expose aggregates such as counts.
     *
     * Declaring at least one attribute here is what registers the
     * `/computed` route for the model.
     *
     * An attribute may also declare PARAMETERS the client supplies as
     * `?attributes[name][param]=value`. Use the extended form — a map carrying
     * `params` (and optionally `optional` and `using`) — and the bound arguments
     * are appended after $user, in declared order. An attribute with a REQUIRED
     * parameter is skipped by a bare `GET /computed` rather than 403'd, so
     * adding one never breaks a client that asks for everything.
     *
     * @example
     * ```php
     * public static function rhinoCollectionComputedAttributes(): array
     * {
     *     return [
     *         'active_users_count' => fn ($query, $user) => $query->where('status', 'active')->count(),
     *         'blocked_users_count' => fn ($query, $user) => $query->where('status', 'blocked')->count(),
     *         'revenue' => [
     *             'params' => ['from', 'to'],
     *             'using'  => fn ($query, $user, $from, $to) =>
     *                 $query->whereBetween('created_at', [$from, $to])->sum('total'),
     *         ],
     *     ];
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public static function rhinoCollectionComputedAttributes(): array
    {
        return [];
    }

    /**
     * Evaluate the selected opt-in record-level computed attributes.
     *
     * Names that are not declared are silently skipped — the controller has
     * already rejected unknown/forbidden names with a 403, and a direct
     * asRhinoJson() caller should not be able to force an arbitrary call.
     *
     * An attribute that declares a required parameter is likewise skipped when
     * $arguments carries no entry for it, so a custom controller calling
     * asRhinoJson($user, ['tickets_since']) gets a missing key rather than an
     * ArgumentCountError.
     *
     * @param  array<string>  $names
     * @param  mixed  $user
     * @param  array<string, array<int, mixed>>  $arguments
     * @return array<string, mixed>
     */
    protected function rhinoResolveRecordComputedAttributes(array $names, $user, array $arguments = []): array
    {
        if (empty($names)) {
            return [];
        }

        $declared = $this->rhinoRecordComputedAttributes();
        if (!is_array($declared) || empty($declared)) {
            return [];
        }

        $specs = \Rhino\Support\ComputedAttributeSpec::normalize($declared);

        $resolved = [];
        foreach ($names as $name) {
            if (!is_string($name) || !array_key_exists($name, $specs)) {
                continue;
            }

            $spec = $specs[$name];

            if (array_key_exists($name, $arguments)) {
                $args = array_values((array) $arguments[$name]);
            } elseif (\Rhino\Support\ComputedAttributeSpec::requiresArguments($spec)) {
                continue;
            } else {
                $args = [];
            }

            $callback = $spec['using'];
            $resolved[$name] = is_callable($callback) ? $callback($this, $user, ...$args) : $callback;
        }

        return $resolved;
    }

    /**
     * Resolve the full list of hidden columns for a given user.
     * Combines base, additional, and policy-driven hidden columns.
     *
     * Unlike getHidden() which reads auth() implicitly, this method
     * accepts the user explicitly for consistent behavior in serialization.
     *
     * @param  mixed  $user
     * @return array<string>
     */
    protected function resolveAllHiddenColumns($user): array
    {
        $hidden = array_merge($this->hidden, $this->baseHiddenColumns);

        if (property_exists(static::class, 'additionalHiddenColumns')) {
            $ref = new \ReflectionProperty(static::class, 'additionalHiddenColumns');
            $additional = $ref->isStatic() ? static::$additionalHiddenColumns : $this->additionalHiddenColumns;
            if (is_array($additional)) {
                $hidden = array_merge($hidden, $additional);
            }
        }

        $cacheKey = static::class . ':' . ($user?->id ?? 'guest') . ':' . (request()->attributes->get('organization')?->id ?? 'no-org');
        if (!isset(static::$hiddenColumnsCache[$cacheKey])) {
            static::$hiddenColumnsCache[$cacheKey] = $this->resolveHiddenColumnsFromPolicy($user);
        }

        return array_unique(array_merge($hidden, static::$hiddenColumnsCache[$cacheKey]));
    }

    /**
     * Resolve permitted attributes from the policy, or null if no policy.
     *
     * @param  mixed  $user
     * @return array<string>|null
     */
    protected function resolvePolicyPermittedAttributes($user): ?array
    {
        try {
            $policy = Gate::getPolicyFor($this);

            if ($policy instanceof HasPermittedAttributes) {
                return $policy->permittedAttributesForShow($user);
            }
        } catch (\Exception $e) {
            // Policy resolution failed
        }

        return null;
    }

    public function hideAdditionalColumns(array $columns)
    {
        $this->hidden = array_merge($this->hidden, $columns);
        return $this;
    }

    protected function getColumns(): array
    {
        return $this->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($this->getTable());
    }

    /**
     * Clear the hidden columns cache.
     * Useful for testing or when user context changes mid-request.
     */
    public static function clearHiddenColumnsCache(): void
    {
        static::$hiddenColumnsCache = [];
    }
}
