<?php

namespace Rhino\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Rhino\Contracts\HasPermittedAttributes;
use Rhino\Contracts\HasPermittedScopes;
use Rhino\Exceptions\InvalidComputedAttributeArguments;
use Rhino\Exceptions\InvalidScopeArguments;
use Rhino\Support\ComputedAttributeSpec;
use Rhino\Support\ScopeSpec;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class GlobalController extends Controller
{
    use \Rhino\Support\ScopesToOrganization;

    /**
     * Fallback for how many named scopes one request may combine, used when
     * `rhino.max_scopes_per_request` is absent — an app that published its
     * config before the key existed keeps today's behavior.
     *
     * Scopes are arbitrary query fragments, so stacking many of them is a good
     * way to build an accidental cross join; three covers every real listing
     * and keeps the blast radius small.
     */
    protected const DEFAULT_MAX_SCOPES_PER_REQUEST = 3;

    protected $modelClass;

    /**
     * Column listings per table, so the query gate does not describe the schema
     * once per attribute. Request-lived: the controller is resolved per request.
     *
     * @var array<string, array<string>>
     */
    protected static $columnCache = [];

    /**
     * Get the model slug from the route defaults.
     * The model is set via ->defaults('model', $slug) during route registration.
     */
    protected function getModelSlug(Request $request): string
    {
        return $request->route()->defaults['model']
            ?? $request->route('model')
            ?? abort(404, 'Model not specified');
    }

    /**
     * Get the resource ID from the route parameters.
     */
    protected function getResourceId(Request $request): string
    {
        return $request->route('id')
            ?? abort(404, 'Resource ID not specified');
    }

    /**
     * Resolve the column matched against the {id} URL segment for the current
     * model (member endpoints only). Delegates to the central resolver:
     * model static $routeKey → config('rhino.route_key') → getRouteKeyName().
     */
    protected function resolveRouteKeyName(): string
    {
        return \Rhino\Facades\Rhino::routeKeyName($this->modelClass);
    }

    /**
     * Resolve and set the model class for the given model name.
     */
    protected function resolveModelClass(string $model): void
    {
        if (!isset(config('rhino.models')[$model])) {
            abort(404, "The {$model} model does not exist");
        }

        $modelClass = config('rhino.models')[$model];

        if (!class_exists($modelClass)) {
            abort(404, "The {$model} model does not exist");
        }

        $this->modelClass = app()->make($modelClass);
    }

    // ------------------------------------------------------------------
    // Serialization
    // ------------------------------------------------------------------

    /**
     * Serialize a single record using the HidableColumns asRhinoJson method.
     *
     * If the model uses HidableColumns (i.e. has asRhinoJson), the record
     * is serialized through the policy-aware path that respects blacklists,
     * whitelists, and computed attributes.
     *
     * Models without HidableColumns fall back to toArray().
     */
    protected function serializeRecord($record, array $computedAttributes = [], array $computedArguments = []): array
    {
        if (method_exists($record, 'asRhinoJson')) {
            try {
                $user = auth('sanctum')->user();
            } catch (\InvalidArgumentException $e) {
                $user = auth()->user();
            }
            return $record->asRhinoJson($user, $computedAttributes, $computedArguments);
        }

        return $record->toArray();
    }

    /**
     * Serialize a collection of records using serializeRecord.
     *
     * @param  array<string>  $computedAttributes  Opt-in record-level computed
     *   attributes selected by the client via `?computed_attributes=`.
     * @param  array<string, array<int, mixed>>  $computedArguments  Positional
     *   arguments per attribute name, bound from the bracket query form.
     */
    protected function serializeCollection($records, array $computedAttributes = [], array $computedArguments = []): array
    {
        return collect($records)
            ->map(fn ($record) => $this->serializeRecord($record, $computedAttributes, $computedArguments))
            ->values()
            ->all();
    }

    /**
     * Accept either shape a computed-attribute resolver may return: the
     * `['names' => [...], 'arguments' => [...]]` map this version produces, or
     * a plain list of names, which is what an application that overrode
     * resolveRequestedComputedAttributes() before 4.9.0 still returns.
     *
     * @param  mixed  $selection
     * @return array{names: array<string>, arguments: array<string, array<int, mixed>>}
     */
    protected function normalizeComputedSelection($selection): array
    {
        if (is_array($selection) && isset($selection['names']) && is_array($selection['names'])) {
            return [
                'names' => array_values($selection['names']),
                'arguments' => is_array($selection['arguments'] ?? null) ? $selection['arguments'] : [],
            ];
        }

        return ['names' => array_values((array) $selection), 'arguments' => []];
    }

    // ------------------------------------------------------------------
    // CRUD Actions
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        Gate::forUser(auth('sanctum')->user())->authorize('viewAny', $this->modelClass::class);

        $computedSelection = $this->resolveRequestedComputedAttributes($request);
        if ($computedSelection instanceof \Illuminate\Http\JsonResponse) {
            return $computedSelection;
        }
        $computedSelection = $this->normalizeComputedSelection($computedSelection);

        $query = QueryBuilder::for($this->modelClass::class);

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        if ($scopeError = $this->applyNamedScope($query, $request)) {
            return $scopeError;
        }

        if ($attributeError = $this->guardQueryAttributes($request)) {
            return $attributeError;
        }

        if (property_exists($this->modelClass, 'allowedFilters')) {
            $query = $query->allowedFilters($this->normalizeFilters($this->queryableFilters()));
        } elseif ($request->has('filters')) {
            return response()->json(['message' => 'Filters are not allowed'], 403);
        }
        if (property_exists($this->modelClass, 'defaultSort')) {
            $query = $query->defaultSort($this->modelClass::$defaultSort);
        }
        if (property_exists($this->modelClass, 'allowedSorts')) {
            $query = $query->allowedSorts($this->queryableSorts());
        }
        if (property_exists($this->modelClass, 'allowedFields')) {
            $query = $query->allowedFields($this->modelClass::$allowedFields);
        }
        $includeAuthResponse = $this->authorizeIncludes($request);
        if ($includeAuthResponse !== null) {
            return $includeAuthResponse;
        }
        if (property_exists($this->modelClass, 'allowedIncludes')) {
            $query = $query->allowedIncludes($this->modelClass::$allowedIncludes);
        }

        $this->applySearch($query, $request);

        // Pagination: use ?per_page=N for paginated results, omit for all results
        // Models can set a default via Laravel's $perPage property or static $paginationEnabled
        $perPage = $request->input('per_page');
        $paginationEnabled = property_exists($this->modelClass, 'paginationEnabled')
            ? $this->modelClass::$paginationEnabled
            : false;

        if ($perPage !== null || $paginationEnabled) {
            $perPage = (int) ($perPage ?? $this->modelClass->getPerPage());
            $perPage = max(1, min($perPage, 100)); // clamp between 1 and 100

            $paginator = $query->paginate($perPage);

            return response()->json(['data' => $this->serializeCollection($paginator->items(), $computedSelection['names'], $computedSelection['arguments'])])
                ->header('X-Current-Page', $paginator->currentPage())
                ->header('X-Last-Page', $paginator->lastPage())
                ->header('X-Per-Page', $paginator->perPage())
                ->header('X-Total', $paginator->total());
        }

        return response()->json(['data' => $this->serializeCollection($query->get(), $computedSelection['names'], $computedSelection['arguments'])]);
    }

    public function store(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $user = auth('sanctum')->user();
        Gate::forUser($user)->authorize('create', $this->modelClass::class);

        // In tenant context, organization_id is managed by the framework — strip from input and validation.
        $isTenant = $this->stripOrganizationId($request);

        // Legacy path: model has $validationRulesStore/$validationRulesUpdate — preserve exact current behavior
        if ($this->modelClass->hasLegacyRulesConfig()) {
            $validator = $this->modelClass->validateStore($request);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            $validated = $validator->validated();
            $this->addOrganizationToData($validated);
            $record = $this->modelClass::create($validated);
            return response()->json($this->serializeRecord($record), 201);
        }

        // New policy-driven path: Policy controls which fields are permitted
        $permittedFields = $this->resolvePermittedFields($user, 'create');

        // In tenant context, exclude organization_id from permission checks and validation
        // since it is managed by the framework (addOrganizationToData).
        if ($isTenant && $permittedFields !== ['*']) {
            $permittedFields = array_values(array_diff($permittedFields, ['organization_id']));
        }

        // Check for forbidden fields → 403
        $forbidden = $this->modelClass->findForbiddenFields($request, $permittedFields);
        if (!empty($forbidden)) {
            return response()->json([
                'message' => 'You are not allowed to set the following field(s): ' . implode(', ', $forbidden),
            ], 403);
        }

        // Validate format rules only (permission already checked above)
        $validator = $this->modelClass->validateForAction($request, $permittedFields, 'store');
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        $this->addOrganizationToData($validated);

        $record = $this->modelClass::create($validated);
        return response()->json($this->serializeRecord($record), 201);
    }

    public function show(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $id = $this->getResourceId($request);
        $organization = request()->attributes->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);

        // For the Organization resource, scope already restricts to the current org; do not filter by route id to avoid no-result
        $query = QueryBuilder::for($this->modelClass::class);
        if (! $isOrganizationResource) {
            $query->where($this->resolveRouteKeyName(), $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('view', $object);

        $computedSelection = $this->resolveRequestedComputedAttributes($request);
        if ($computedSelection instanceof \Illuminate\Http\JsonResponse) {
            return $computedSelection;
        }
        $computedSelection = $this->normalizeComputedSelection($computedSelection);

        if (property_exists($this->modelClass, 'allowedFields')) {
            $query = $query->allowedFields($this->modelClass::$allowedFields);
        }
        $includeAuthResponse = $this->authorizeIncludes($request);
        if ($includeAuthResponse !== null) {
            return $includeAuthResponse;
        }
        if (property_exists($this->modelClass, 'allowedIncludes')) {
            $query = $query->allowedIncludes($this->modelClass::$allowedIncludes);
        }

        $model = $query->firstOrFail();

        return response()->json($this->serializeRecord($model, $computedSelection['names'], $computedSelection['arguments']));
    }

    public function update(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $id = $this->getResourceId($request);
        $organization = request()->attributes->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }

        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class);
        if (! $isOrganizationResource) {
            $query->where($this->resolveRouteKeyName(), $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        $user = auth('sanctum')->user();
        Gate::forUser($user)->authorize('update', $object);

        // In tenant context, organization_id cannot be changed — reject with 403.
        $isTenant = (bool) request()->attributes->get('organization');
        $rejected = $this->rejectOrganizationIdChange($request);
        if ($rejected) {
            return $rejected;
        }

        // Legacy path: model has $validationRulesStore/$validationRulesUpdate — preserve exact current behavior
        if ($this->modelClass->hasLegacyRulesConfig()) {
            $validator = $this->modelClass->validateUpdate($request);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            $validated = $validator->validated();
            $object->update($validated);
            $object->refresh();
            return response()->json($this->serializeRecord($object));
        }

        // New policy-driven path: Policy controls which fields are permitted
        $permittedFields = $this->resolvePermittedFields($user, 'update');

        // In tenant context, exclude organization_id from permission checks and validation.
        if ($isTenant && $permittedFields !== ['*']) {
            $permittedFields = array_values(array_diff($permittedFields, ['organization_id']));
        }

        // Check for forbidden fields → 403
        $forbidden = $this->modelClass->findForbiddenFields($request, $permittedFields);
        if (!empty($forbidden)) {
            return response()->json([
                'message' => 'You are not allowed to set the following field(s): ' . implode(', ', $forbidden),
            ], 403);
        }

        // Validate format rules only (permission already checked above)
        $validator = $this->modelClass->validateForAction($request, $permittedFields, 'update');
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        $object->update($validated);
        $object->refresh();

        return response()->json($this->serializeRecord($object));
    }

    public function destroy(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $id = $this->getResourceId($request);
        $organization = request()->attributes->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class);
        if (! $isOrganizationResource) {
            $query->where($this->resolveRouteKeyName(), $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('delete', $object);

        $object->delete();

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------
    // Soft Delete Endpoints
    // ------------------------------------------------------------------
    // These endpoints are only registered for models that use SoftDeletes.
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Computed Attributes
    // ------------------------------------------------------------------

    /**
     * GET /api/{resource}/computed?attributes=a,b
     *
     * Collection-level computed attributes: each declared callable is evaluated
     * ONCE over the whole (scoped + filtered) collection instead of once per
     * row, which is what makes aggregates such as `active_users_count` cheap.
     *
     * The query handed to each callable has the organization scope, the model's
     * global scopes, `?scope=`, `?filter[]=` and `?search=` already applied — so
     * the numbers describe exactly the set `index` would have listed. Sorting,
     * sparse fieldsets, includes and pagination are deliberately NOT applied.
     *
     * Omitting `?attributes=` returns every declared attribute the policy allows,
     * minus any that declares a required parameter — those are skipped silently
     * so adding a parameterised attribute never breaks a bare `/computed` call.
     *
     * Attributes that declare parameters take them in the bracket form:
     *
     *   ?attributes[revenue][from]=2026-01-01&attributes[revenue][to]=2026-02-01
     */
    public function computed(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $user = auth('sanctum')->user();
        Gate::forUser($user)->authorize('viewAny', $this->modelClass::class);

        $declared = $this->collectionComputedAttributes();
        $specs = ComputedAttributeSpec::normalize($declared);

        $selection = $this->resolveRequestedCollectionAttributes($request, $declared, $user);
        if ($selection instanceof \Illuminate\Http\JsonResponse) {
            return $selection;
        }
        $selection = $this->normalizeComputedSelection($selection);

        $query = QueryBuilder::for($this->modelClass::class);

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        if ($scopeError = $this->applyNamedScope($query, $request)) {
            return $scopeError;
        }

        if ($attributeError = $this->guardQueryAttributes($request)) {
            return $attributeError;
        }

        if (property_exists($this->modelClass, 'allowedFilters')) {
            $query = $query->allowedFilters($this->normalizeFilters($this->queryableFilters()));
        } elseif ($request->has('filters')) {
            return response()->json(['message' => 'Filters are not allowed'], 403);
        }

        $this->applySearch($query, $request);

        $data = [];
        foreach ($selection['names'] as $name) {
            $spec = $specs[$name] ?? ['params' => [], 'optional' => [], 'using' => null];
            $callback = $spec['using'];
            $arguments = $selection['arguments'][$name] ?? [];

            // Every attribute gets its OWN clone so one callable's constraints
            // can never leak into the next one's result. Client arguments are
            // appended after the builder and the user, in declared order — the
            // builder handed over is already organization-scoped, filtered and
            // searched, and no argument can widen it.
            $data[$name] = is_callable($callback)
                ? $callback($query->clone()->getEloquentBuilder(), $user, ...$arguments)
                : $callback;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * The model's declared collection-level computed attributes (name => callable).
     *
     * @return array<string, mixed>
     */
    protected function collectionComputedAttributes(): array
    {
        if (! method_exists($this->modelClass, 'rhinoCollectionComputedAttributes')) {
            return [];
        }

        $declared = $this->modelClass::rhinoCollectionComputedAttributes();

        return is_array($declared) ? $declared : [];
    }

    /**
     * Parse and authorize `?attributes=` for the /computed endpoint, in every
     * accepted form:
     *
     *   ?attributes=a,b                       legacy comma list, no arguments
     *   ?attributes[revenue]=                 one name, no arguments
     *   ?attributes[since]=2026-01-01         binds to the single declared param
     *   ?attributes[revenue][from]=a&...      named arguments
     *
     * Returns `['names' => [...], 'arguments' => [name => [...positional]]]`,
     * or a 403 JsonResponse. An undeclared name and a policy-denied name produce
     * the SAME error so the endpoint never reveals which attributes a model
     * declares — and both checks run BEFORE any argument binding, so the more
     * specific argument messages can only ever be seen for a name the caller was
     * already allowed to use.
     *
     * @param  array<string, mixed>  $declared
     * @return array{names: array<string>, arguments: array<string, array<int, mixed>>}|\Illuminate\Http\JsonResponse
     */
    protected function resolveRequestedCollectionAttributes(Request $request, array $declared, $user)
    {
        $raw = $request->input('attributes');
        $specs = ComputedAttributeSpec::normalize($declared);

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            // No selection: every declared attribute the policy allows, minus
            // the ones that cannot run without client arguments.
            $names = [];
            foreach ($specs as $name => $spec) {
                if (! $this->computedAttributeAllowed($name, $user)) {
                    continue;
                }
                if (ComputedAttributeSpec::requiresArguments($spec)) {
                    continue;
                }
                $names[] = (string) $name;
            }

            return ['names' => $names, 'arguments' => []];
        }

        $requested = $this->parseAttributeSelection($raw);
        if ($requested instanceof \Illuminate\Http\JsonResponse) {
            return $requested;
        }

        return $this->bindAttributeSelection($requested, $specs, $user);
    }

    /**
     * Parse and authorize `?computed_attributes=` for index/show/trashed — the
     * OPT-IN record-level computed attributes. Accepts the same four forms as
     * `?attributes=` (see resolveRequestedCollectionAttributes()).
     *
     * Absent or empty means "none", which is byte-for-byte the pre-feature
     * behavior.
     *
     * @return array{names: array<string>, arguments: array<string, array<int, mixed>>}|\Illuminate\Http\JsonResponse
     */
    protected function resolveRequestedComputedAttributes(Request $request)
    {
        $raw = $request->input('computed_attributes');

        if ($raw === null || $raw === '') {
            return ['names' => [], 'arguments' => []];
        }

        $requested = $this->parseAttributeSelection($raw);
        if ($requested instanceof \Illuminate\Http\JsonResponse) {
            return $requested;
        }

        if ($requested === []) {
            return ['names' => [], 'arguments' => []];
        }

        $declared = method_exists($this->modelClass, 'rhinoRecordComputedAttributes')
            ? $this->modelClass->rhinoRecordComputedAttributes()
            : [];
        $declared = is_array($declared) ? $declared : [];

        return $this->bindAttributeSelection(
            $requested,
            ComputedAttributeSpec::normalize($declared),
            auth('sanctum')->user()
        );
    }

    /**
     * Turn the raw query value into an ordered list of `[name, rawArguments]`
     * pairs — a list rather than a map, because PHP would coerce a numeric
     * attribute name back to an int key.
     *
     * @param  mixed  $raw
     * @return array<int, array{0: string, 1: mixed}>|\Illuminate\Http\JsonResponse
     */
    protected function parseAttributeSelection($raw)
    {
        if (is_string($raw)) {
            return array_map(
                fn ($name) => [$name, ''],
                $this->parseAttributeList($raw)
            );
        }

        if (! is_array($raw)) {
            return response()->json(['message' => 'Computed attributes are not allowed'], 403);
        }

        $pairs = [];
        foreach ($raw as $key => $value) {
            // A positional list (?attributes[]=x) or a blank key names nothing:
            // reject before any lookup.
            if (! is_string($key) || $key === '') {
                return response()->json(['message' => 'Computed attributes are not allowed'], 403);
            }

            $pairs[] = [$key, $value];
        }

        return $pairs;
    }

    /**
     * Gate every requested attribute, then bind its arguments.
     *
     * @param  array<int, array{0: string, 1: mixed}>  $requested
     * @param  array<string, array{params: array<string>, optional: array<string>, using: mixed}>  $specs
     * @return array{names: array<string>, arguments: array<string, array<int, mixed>>}|\Illuminate\Http\JsonResponse
     */
    protected function bindAttributeSelection(array $requested, array $specs, $user)
    {
        $names = [];
        $arguments = [];

        foreach ($requested as [$name, $rawArguments]) {
            // Gate first — declared AND policy-visible — so nothing below can
            // distinguish an undeclared name from a forbidden one.
            if (! array_key_exists($name, $specs) || ! $this->computedAttributeAllowed($name, $user)) {
                return response()->json(['message' => "Computed attribute '{$name}' is not allowed"], 403);
            }

            try {
                $arguments[$name] = ComputedAttributeSpec::bind($name, $specs[$name], $rawArguments);
            } catch (InvalidComputedAttributeArguments $e) {
                return response()->json(['message' => $e->getMessage()], 403);
            }

            $names[] = $name;
        }

        return ['names' => array_values(array_unique($names)), 'arguments' => $arguments];
    }

    /**
     * Split a comma-separated attribute list, trimming blanks and duplicates.
     *
     * @return array<string>
     */
    protected function parseAttributeList(string $raw): array
    {
        $names = array_map('trim', explode(',', $raw));

        return array_values(array_unique(array_filter($names, fn ($name) => $name !== '')));
    }

    /**
     * Whether the policy lets this user see a computed attribute.
     *
     * Computed attributes go through the SAME gate as columns:
     * `hiddenAttributesForShow()` blacklists, and `permittedAttributesForShow()`
     * whitelists unless it returns the `['*']` default.
     */
    protected function computedAttributeAllowed(string $name, $user): bool
    {
        return $this->attributeVisibleToUser($this->modelClass, $name, $user);
    }

    /**
     * List soft-deleted (trashed) records.
     */
    public function trashed(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $this->ensureSoftDeletes();

        Gate::forUser(auth('sanctum')->user())->authorize('viewTrashed', $this->modelClass::class);

        $computedSelection = $this->resolveRequestedComputedAttributes($request);
        if ($computedSelection instanceof \Illuminate\Http\JsonResponse) {
            return $computedSelection;
        }
        $computedSelection = $this->normalizeComputedSelection($computedSelection);

        $query = QueryBuilder::for($this->modelClass::class)->onlyTrashed();

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        if ($scopeError = $this->applyNamedScope($query, $request)) {
            return $scopeError;
        }

        if ($attributeError = $this->guardQueryAttributes($request)) {
            return $attributeError;
        }

        if (property_exists($this->modelClass, 'allowedFilters')) {
            $query = $query->allowedFilters($this->normalizeFilters($this->queryableFilters()));
        }
        if (property_exists($this->modelClass, 'defaultSort')) {
            $query = $query->defaultSort($this->modelClass::$defaultSort);
        }
        if (property_exists($this->modelClass, 'allowedSorts')) {
            $query = $query->allowedSorts($this->queryableSorts());
        }
        if (property_exists($this->modelClass, 'allowedFields')) {
            $query = $query->allowedFields($this->modelClass::$allowedFields);
        }
        $includeAuthResponse = $this->authorizeIncludes($request);
        if ($includeAuthResponse !== null) {
            return $includeAuthResponse;
        }
        if (property_exists($this->modelClass, 'allowedIncludes')) {
            $query = $query->allowedIncludes($this->modelClass::$allowedIncludes);
        }

        $this->applySearch($query, $request);

        // Pagination support (same as index)
        $perPage = $request->input('per_page');
        $paginationEnabled = property_exists($this->modelClass, 'paginationEnabled')
            ? $this->modelClass::$paginationEnabled
            : false;

        if ($perPage !== null || $paginationEnabled) {
            $perPage = (int) ($perPage ?? $this->modelClass->getPerPage());
            $perPage = max(1, min($perPage, 100));

            $paginator = $query->paginate($perPage);

            return response()->json(['data' => $this->serializeCollection($paginator->items(), $computedSelection['names'], $computedSelection['arguments'])])
                ->header('X-Current-Page', $paginator->currentPage())
                ->header('X-Last-Page', $paginator->lastPage())
                ->header('X-Per-Page', $paginator->perPage())
                ->header('X-Total', $paginator->total());
        }

        return response()->json(['data' => $this->serializeCollection($query->get(), $computedSelection['names'], $computedSelection['arguments'])]);
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restore(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $this->ensureSoftDeletes();

        $id = $this->getResourceId($request);
        $organization = request()->attributes->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class)->onlyTrashed();
        if (! $isOrganizationResource) {
            $query->where($this->resolveRouteKeyName(), $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('restore', $object);

        $object->restore();
        $object->refresh();

        return response()->json($this->serializeRecord($object));
    }

    /**
     * Permanently delete a record (force delete).
     */
    public function forceDelete(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $this->ensureSoftDeletes();

        $id = $this->getResourceId($request);
        $organization = request()->attributes->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class)->onlyTrashed();
        if (! $isOrganizationResource) {
            $query->where($this->resolveRouteKeyName(), $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('forceDelete', $object);

        $object->forceDelete();

        return response()->json(null, 204);
    }

    /**
     * Ensure the model uses SoftDeletes trait.
     */
    protected function ensureSoftDeletes(): void
    {
        if (!in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($this->modelClass))) {
            abort(404, 'This resource does not support soft deletes');
        }
    }

    /**
     * When the resource is Organization, require the route id to match the current organization's key.
     * Returns a 404 JSON response if the user is requesting a different org (avoids leaking existence).
     */
    protected function organizationIdMismatchResponse(Request $request, $organization): ?Response
    {
        if (!$organization || get_class($organization) !== get_class($this->modelClass)) {
            return null;
        }
        $id = $request->route('id');
        if ($id === null) {
            return null;
        }
        // Compare against the organization's resolved route-key attribute
        // (defaults to the primary key, keeping historical behavior).
        $routeKeyName = \Rhino\Facades\Rhino::routeKeyName($organization);
        if ((string) $organization->getAttribute($routeKeyName) !== (string) $id) {
            return response()->json(['message' => 'Organization not found'], 404);
        }
        return null;
    }

    /**
     * Apply organization scope to query when an organization is present on the request.
     *
     * Thin wrapper over the ScopesToOrganization trait so all existing call
     * sites stay unchanged. The trait now owns the resolution logic, shared
     * with Rhino::query() for non-request contexts.
     */
    protected function applyOrganizationScope($query): void
    {
        $this->scopeQueryToOrganization($query, $this->modelClass, request()->attributes->get('organization'));
    }

    /**
     * Convert plain string filter names to AllowedFilter::exact().
     * Spatie defaults strings to AllowedFilter::partial() (LIKE %value%),
     * which is wrong for ID and boolean fields. Exact match is the safe default.
     * Models can still use AllowedFilter::partial() explicitly for text search.
     */
    protected function normalizeFilters(array $filters): array
    {
        return array_map(function ($filter) {
            if ($filter instanceof AllowedFilter) {
                return $filter;
            }

            return AllowedFilter::exact($filter);
        }, $filters);
    }

    /**
     * Apply the client-selected named scopes (`?scope=`) or the model's default
     * scope. Returns a 403 JsonResponse when a scope is not allowed, otherwise
     * null.
     *
     * Two wire forms, which cannot be mixed in one request because they share
     * the same query key:
     *
     *   ?scope=archived                      legacy, one scope, no arguments
     *   ?scope[archived]=                    same thing in the bracket form
     *   ?scope[since]=2026-01-01             one argument, bound to the single
     *                                        declared parameter
     *   ?scope[window][from]=a&scope[window][to]=b   named arguments
     *
     * Arguments are bound BY NAME to the parameters the model declared in
     * `$allowedScopes` and passed to the scope in declared order, after the
     * current user. A scope that declares no parameters never receives any.
     *
     * A name must be declared by the model AND permitted by the policy's
     * `permittedScopes()`. Both failures return the same message, so the
     * endpoint never reveals which scopes exist.
     *
     * Only index(), trashed() and computed() call this; show/update/destroy are
     * unscoped. The default scope is a listing convenience, not a security
     * boundary — selecting another allowed scope replaces it. Mandatory
     * restrictions belong in global scopes.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function applyNamedScope($query, Request $request)
    {
        $raw = $request->input('scope');

        $declared = property_exists($this->modelClass, 'allowedScopes')
            ? ScopeSpec::normalize((array) $this->modelClass::$allowedScopes)
            : [];

        $default = property_exists($this->modelClass, 'defaultScope')
            ? $this->modelClass::$defaultScope
            : null;

        // Nothing requested: the model's default scope, which takes no arguments.
        if ($raw === null || $raw === '' || $raw === []) {
            if ($default === null || $default === '') {
                return null;
            }

            return $this->runNamedScope($query, (string) $default, []);
        }

        if (is_string($raw)) {
            // Legacy form — one scope, no arguments.
            $requested = [$raw => ''];
        } elseif (is_array($raw)) {
            $requested = $raw;
        } else {
            return response()->json(['message' => 'Scope is not allowed'], 403);
        }

        // A positional list (?scope[]=a) names nothing: reject before any lookup.
        foreach (array_keys($requested) as $name) {
            if (! is_string($name) || $name === '') {
                return response()->json(['message' => 'Scope is not allowed'], 403);
            }
        }

        if (count($requested) > $this->maxScopesPerRequest()) {
            return response()->json(['message' => 'Too many scopes requested'], 403);
        }

        $permitted = $this->permittedScopeNames();

        foreach ($requested as $name => $rawArguments) {
            $name = (string) $name;

            // Declared by the model, or the model's own default scope (which is
            // implicitly requestable by name).
            if (! array_key_exists($name, $declared) && $name !== $default) {
                return response()->json(['message' => "Scope '{$name}' is not allowed"], 403);
            }

            if ($permitted !== ['*'] && ! in_array($name, $permitted, true)) {
                return response()->json(['message' => "Scope '{$name}' is not allowed"], 403);
            }

            $spec = $declared[$name] ?? ['params' => [], 'optional' => []];

            try {
                $arguments = ScopeSpec::bind($name, $spec, $rawArguments);
            } catch (InvalidScopeArguments $e) {
                return response()->json(['message' => $e->getMessage()], 403);
            }

            if ($error = $this->runNamedScope($query, $name, $arguments)) {
                return $error;
            }
        }

        return null;
    }

    /**
     * How many named scopes this app allows in one request
     * (`rhino.max_scopes_per_request`). A non-numeric or non-positive value
     * falls back to the default rather than disabling scopes outright.
     */
    protected function maxScopesPerRequest(): int
    {
        $configured = config('rhino.max_scopes_per_request', static::DEFAULT_MAX_SCOPES_PER_REQUEST);

        if (! is_numeric($configured) || (int) $configured < 1) {
            return static::DEFAULT_MAX_SCOPES_PER_REQUEST;
        }

        return (int) $configured;
    }

    /**
     * Run one already-authorized named scope against the query.
     *
     * @param  array<int, mixed>  $arguments
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function runNamedScope($query, string $name, array $arguments)
    {
        // hasNamedScope() guarantees only scopeXxx()/#[Scope] methods are ever
        // invoked — never an arbitrary model or builder method.
        if (! $this->modelClass->hasNamedScope($name)) {
            return response()->json(['message' => "Scope '{$name}' is not allowed"], 403);
        }

        // Dispatch through Builder::scopes() (NOT $query->{$name}(...)) so the
        // call can never be shadowed by a real QueryBuilder/Builder method or
        // macro (e.g. a scope named 'delete' would otherwise execute
        // Builder::delete()). Builder::scopes() routes straight to callNamedScope.
        $query->scopes([$name => array_merge([auth('sanctum')->user()], $arguments)]);

        return null;
    }

    /**
     * Scope names this user may select, or `['*']` when the policy does not
     * restrict them (the default, and the behavior of every policy written
     * before `permittedScopes()` existed).
     *
     * @return array<string>
     */
    protected function permittedScopeNames(): array
    {
        try {
            $policy = Gate::getPolicyFor($this->modelClass);
        } catch (\Exception $e) {
            $policy = null;
        }

        if ($policy === null || ! method_exists($policy, 'permittedScopes')) {
            return ['*'];
        }

        $permitted = $policy->permittedScopes(auth('sanctum')->user());

        if (! is_array($permitted)) {
            return ['*'];
        }

        return array_values(array_map('strval', $permitted));
    }

    /**
     * 403 when the request asks to filter or sort by an attribute this user's
     * policy hides.
     *
     * Attribute permissions used to apply only when serializing, so a hidden
     * column stayed usable as a query predicate: `?filter[salary]=300000` never
     * printed a salary but told the caller whose salary it was, and `?sort=`
     * leaked the whole ordering. Filters and sorts now go through the same gate
     * as the response body.
     *
     * A name the model never allowlisted is still ignored rather than refused,
     * so this cannot be used to discover which columns exist.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function guardQueryAttributes(Request $request)
    {
        $filters = $request->input('filter');
        if (is_array($filters)) {
            $declared = property_exists($this->modelClass, 'allowedFilters')
                ? (array) $this->modelClass::$allowedFilters
                : [];

            foreach (array_keys($filters) as $key) {
                if (! is_string($key)) {
                    continue;
                }

                $entry = $this->declaredEntryFor($declared, $key, fn ($e) => $this->filterNames($e));

                // Not allowlisted at all: ignored, as before. Refusing here would
                // tell the caller which columns exist.
                if ($entry === null) {
                    continue;
                }

                [, $attribute] = $this->filterNames($entry);

                if (! $this->queryAttributeAllowed($attribute)) {
                    return response()->json(['message' => "Filter '{$key}' is not allowed"], 403);
                }
            }
        }

        $sort = $request->input('sort');
        if (is_string($sort) && $sort !== '') {
            $declared = property_exists($this->modelClass, 'allowedSorts')
                ? (array) $this->modelClass::$allowedSorts
                : [];

            foreach (explode(',', $sort) as $field) {
                $field = ltrim(trim($field), '-');

                if ($field === '') {
                    continue;
                }

                $entry = $this->declaredEntryFor($declared, $field, fn ($e) => $this->sortNames($e));

                if ($entry === null) {
                    continue;
                }

                [, $attribute] = $this->sortNames($entry);

                if (! $this->queryAttributeAllowed($attribute)) {
                    return response()->json(['message' => "Sort '{$field}' is not allowed"], 403);
                }
            }
        }

        return null;
    }

    /**
     * The declared entry a client-supplied name refers to, or null when nothing
     * in the allowlist answers to it.
     *
     * @param  array<mixed>  $declared
     * @param  callable(mixed): array<string>  $names
     * @return mixed|null
     */
    protected function declaredEntryFor(array $declared, string $requested, callable $names)
    {
        foreach ($declared as $entry) {
            $entryNames = $names($entry);

            // The FIRST name is the one the client sends; an internal name is a
            // column the entry reads, not something that can be asked for.
            if (($entryNames[0] ?? null) === $requested) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The name a client sends for one `$allowedFilters` entry, and the attribute
     * that entry actually reads. They differ when the filter renames a column
     * (`AllowedFilter::exact('cost', 'salary')`): `cost` is what the URL carries,
     * `salary` is what the policy has an opinion about.
     *
     * @return array{0: string, 1: string}
     */
    protected function filterNames($filter): array
    {
        if ($filter instanceof AllowedFilter) {
            return [$filter->getName(), $filter->getInternalName()];
        }

        $name = is_string($filter) ? $filter : (string) $filter;

        return [$name, $name];
    }

    /**
     * `$allowedFilters` with everything this user may not see removed. The
     * request is already refused by guardQueryAttributes(); this keeps a denied
     * column out of the query builder even if some other path reaches it.
     *
     * @return array<mixed>
     */
    protected function queryableFilters(): array
    {
        return array_values(array_filter(
            (array) $this->modelClass::$allowedFilters,
            fn ($filter) => $this->queryAttributeAllowed($this->filterNames($filter)[1])
        ));
    }

    /**
     * `$allowedSorts` with everything this user may not see removed.
     *
     * @return array<mixed>
     */
    protected function queryableSorts(): array
    {
        return array_values(array_filter(
            (array) $this->modelClass::$allowedSorts,
            fn ($sort) => $this->queryAttributeAllowed($this->sortNames($sort)[1])
        ));
    }

    /**
     * The name a client sends for one `$allowedSorts` entry, and the attribute
     * that entry actually orders by (`AllowedSort::field('cost', 'salary')`).
     * Casting the object to a string is a fatal error, so ask it for its names.
     *
     * @return array{0: string, 1: string}
     */
    protected function sortNames($sort): array
    {
        if ($sort instanceof AllowedSort) {
            return [$sort->getName(), $sort->getInternalName()];
        }

        $name = is_string($sort) ? $sort : (string) $sort;

        return [$name, $name];
    }

    /**
     * Whether this user may use an attribute as a query predicate. Dotted names
     * (`user.name`) are checked against the related model's own policy; an
     * unresolvable relation is left alone, so nothing that worked before starts
     * failing for a reason nobody can find.
     */
    protected function queryAttributeAllowed(string $name): bool
    {
        return $this->attributePathAllowed($this->modelClass, $name, auth('sanctum')->user(), true);
    }

    /**
     * @param  object|string  $model
     * @param  bool  $queryGate  Whether this is the filter/sort/search gate,
     *   where a name that is not a column of the model is a label rather than
     *   an attribute (see attributeVisibleToUser).
     */
    protected function attributePathAllowed($model, string $path, $user, bool $queryGate = false): bool
    {
        if (str_contains($path, '.')) {
            [$relation, $rest] = explode('.', $path, 2);

            try {
                $instance = is_object($model) ? $model : app($model);

                if (! method_exists($instance, $relation)) {
                    return true;
                }

                $related = $instance->{$relation}()->getRelated();
            } catch (\Throwable $e) {
                return true;
            }

            return $this->attributePathAllowed($related, $rest, $user, $queryGate);
        }

        return $this->attributeVisibleToUser($model, $path, $user, $queryGate);
    }

    /**
     * The shared gate: `hiddenAttributesForShow()` blacklists, and
     * `permittedAttributesForShow()` whitelists unless it returns `['*']`.
     *
     * `$queryGate` relaxes the whitelist arm for filters and sorts only. A query
     * allowlist may name something that is not a column at all — a callback
     * filter (`AllowedFilter::callback('q', ...)`) or a renamed entry's label —
     * and such a name is not an attribute the policy has an opinion about, so a
     * whitelist policy must not refuse it. An explicitly hidden name is still
     * refused either way, and a real column outside the whitelist still is too.
     *
     * @param  object|string  $model
     */
    protected function attributeVisibleToUser($model, string $name, $user, bool $queryGate = false): bool
    {
        try {
            $policy = Gate::getPolicyFor($model);
        } catch (\Exception $e) {
            return true;
        }

        if (! $policy instanceof HasPermittedAttributes) {
            return true;
        }

        if (in_array($name, $policy->hiddenAttributesForShow($user), true)) {
            return false;
        }

        $permitted = $policy->permittedAttributesForShow($user);

        if ($permitted === ['*'] || in_array($name, $permitted, true)) {
            return true;
        }

        return $queryGate && ! $this->isModelColumn($model, $name);
    }

    /**
     * Whether the model's table actually has this column. Listed once per model
     * per request; a schema that cannot be read (an unusual connection, a test
     * double) answers "yes" so the whitelist keeps applying.
     *
     * @param  object|string  $model
     */
    protected function isModelColumn($model, string $name): bool
    {
        try {
            $instance = is_object($model) ? $model : app($model);
            $table = $instance->getTable();

            if (! isset(static::$columnCache[$table])) {
                static::$columnCache[$table] = $instance->getConnection()
                    ->getSchemaBuilder()
                    ->getColumnListing($table);
            }

            return in_array($name, static::$columnCache[$table], true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Apply global search when ?search=term is present and model has $allowedSearch.
     * Builds OR WHERE (LOWER(col) LIKE %term%) for each column; dot notation uses whereHas for relationships.
     */
    protected function applySearch($query, Request $request): void
    {
        if (! property_exists($this->modelClass, 'allowedSearch')) {
            return;
        }

        $searchTerm = $request->input('search');
        if ($searchTerm === null || $searchTerm === '') {
            return;
        }

        $declared = (array) $this->modelClass::$allowedSearch;
        if ($declared === []) {
            return;
        }

        $columns = array_values(array_filter(
            $declared,
            fn ($column) => $this->queryAttributeAllowed((string) $column)
        ));

        // Every searchable column is hidden from this user. Searching a hidden
        // column tells the caller what is in it, so return nothing rather than
        // silently returning the whole list the client asked to narrow.
        if ($columns === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $term = '%'.strtolower((string) $searchTerm).'%';

        $query->where(function ($q) use ($columns, $term) {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);
                    $q->orWhereHas($relation, function ($sub) use ($field, $term) {
                        $sub->whereRaw('LOWER('.$field.') LIKE ?', [$term]);
                    });
                } else {
                    $q->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            }
        });
    }

    /**
     * Authorize requested ?include= relationships.
     * For each requested include that is in the model's allowedIncludes, checks that the user
     * has viewAny permission on the related model(s). For nested includes (e.g. blog.posts),
     * each segment is authorized. If the user cannot view the related resource, returns a 403
     * JSON response (message only; no exception or stack trace).
     *
     * @return Response|null 403 JSON response when unauthorized, null when authorized
     */
    protected function authorizeIncludes(Request $request): ?Response
    {
        $includeParam = $request->input('include');
        if ($includeParam === null || $includeParam === '') {
            return null;
        }

        $requestedIncludes = array_filter(array_map('trim', explode(',', $includeParam)));
        if (empty($requestedIncludes)) {
            return null;
        }

        $allowedIncludes = property_exists($this->modelClass, 'allowedIncludes')
            ? $this->modelClass::$allowedIncludes
            : [];
        if (empty($allowedIncludes)) {
            return null;
        }

        $user = auth('sanctum')->user();

        foreach ($requestedIncludes as $includePath) {
            $segments = explode('.', $includePath);
            $currentModel = $this->modelClass;
            $currentAllowedIncludes = $allowedIncludes;

            foreach ($segments as $segment) {
                $resolvedSegment = $this->resolveBaseIncludeSegment($segment, $currentAllowedIncludes);
                if ($resolvedSegment === null) {
                    continue 2;
                }

                $relation = $currentModel->{$resolvedSegment}();
                $relatedModelClass = get_class($relation->getRelated());

                $response = Gate::forUser($user)->inspect('viewAny', $relatedModelClass);
                if ($response->denied()) {
                    return response()->json([
                        'message' => "You do not have permission to include {$includePath}.",
                    ], 403);
                }

                $currentModel = $relation->getRelated();
                $currentAllowedIncludes = property_exists($currentModel, 'allowedIncludes')
                    ? $currentModel::$allowedIncludes
                    : [];
            }
        }

        return null;
    }

    /**
     * Resolve an include segment to the base relationship name for authorization.
     * Handles Count/Exists suffixes so that e.g. postsCount is authorized like posts.
     *
     * @param  array<string>  $allowedIncludes
     */
    protected function resolveBaseIncludeSegment(string $segment, array $allowedIncludes): ?string
    {
        if (in_array($segment, $allowedIncludes)) {
            return $segment;
        }

        $countSuffix = config('query-builder.count_suffix', 'Count');
        $existsSuffix = config('query-builder.exists_suffix', 'Exists');

        if ($countSuffix !== '' && str_ends_with($segment, $countSuffix)) {
            $base = substr($segment, 0, -strlen($countSuffix));
            if (in_array($base, $allowedIncludes)) {
                return $base;
            }
        }

        if ($existsSuffix !== '' && str_ends_with($segment, $existsSuffix)) {
            $base = substr($segment, 0, -strlen($existsSuffix));
            if (in_array($base, $allowedIncludes)) {
                return $base;
            }
        }

        return null;
    }


    /**
     * Strip reserved fields from the request input when inside a tenant context.
     *
     * When an organization is resolved from the route (multi-tenant), the
     * organization_id is managed internally and must never be set or changed
     * from user input.
     */
    /**
     * Strip organization_id from request input in tenant context.
     *
     * On store, organization_id is set automatically from the route context
     * via addOrganizationToData(), so any user-supplied value is silently ignored.
     */
    protected function stripOrganizationId(Request $request): bool
    {
        if (!request()->attributes->get('organization')) {
            return false;
        }

        $input = $request->all();
        unset($input['organization_id']);
        $request->replace($input);

        return true;
    }

    /**
     * Reject updates that attempt to change organization_id in tenant context.
     *
     * Returns a 403 response if the request contains organization_id,
     * or null if the request is clean.
     */
    protected function rejectOrganizationIdChange(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (!request()->attributes->get('organization')) {
            return null;
        }

        if ($request->has('organization_id')) {
            return response()->json([
                'message' => 'The organization_id field cannot be changed.',
            ], 403);
        }

        return null;
    }

    /**
     * Add organization_id to data when an organization is present on the request.
     */
    protected function addOrganizationToData(array &$data)
    {
        $organization = request()->attributes->get('organization');
        
        if ($organization && in_array('organization_id', $this->getModelFillable())) {
            $data['organization_id'] = $organization->id;
        }
    }

    /**
     * Get fillable attributes of the model.
     */
    protected function getModelFillable(): array
    {
        $modelInstance = new $this->modelClass;
        return $modelInstance->getFillable();
    }

    /**
     * Resolve permitted fields for the current user from the model's Policy.
     *
     * Checks if the policy implements HasPermittedAttributes and calls the
     * appropriate method (permittedAttributesForCreate or permittedAttributesForUpdate).
     * Returns ['*'] when the policy doesn't implement the interface (allow all).
     *
     * @param  mixed  $user
     * @param  string  $action  'create' or 'update'
     * @return array<string>
     */
    protected function resolvePermittedFields($user, string $action): array
    {
        try {
            $policy = Gate::getPolicyFor($this->modelClass);
        } catch (\Exception $e) {
            return ['*'];
        }

        if (!$policy instanceof HasPermittedAttributes) {
            return ['*'];
        }

        return $action === 'create'
            ? $policy->permittedAttributesForCreate($user)
            : $policy->permittedAttributesForUpdate($user);
    }

    // ------------------------------------------------------------------
    // Nested create/update endpoint
    // ------------------------------------------------------------------

    /**
     * Execute multiple create/update operations in one request (single transaction).
     * Request body: { "operations": [ { "model", "action", "id?" , "data" }, ... ] }
     * Response: { "results": [ { "model", "action", "id", "data" }, ... ] } with full model content in data.
     */
    public function nested(Request $request)
    {
        $operations = $this->validateNestedStructure($request);
        if ($operations instanceof \Illuminate\Http\JsonResponse) {
            return $operations;
        }
        $nestedConfig = config('rhino.nested', []);
        $maxOps = $nestedConfig['max_operations'] ?? null;
        if ($maxOps !== null && count($operations) > (int) $maxOps) {
            return response()->json([
                'message' => 'Too many operations.',
                'errors' => ['operations' => ['Maximum ' . $maxOps . ' operations allowed.']],
            ], 422);
        }
        $allowedModels = $nestedConfig['allowed_models'] ?? null;
        if (is_array($allowedModels)) {
            foreach ($operations as $index => $op) {
                if (!in_array($op['model'], $allowedModels)) {
                    return response()->json([
                        'message' => 'Operation not allowed.',
                        'errors' => ['operations.' . $index . '.model' => ['Model "' . $op['model'] . '" is not allowed for nested operations.']],
                    ], 422);
                }
            }
        }

        $validatedPerOp = [];
        $authResults = []; // for create: null; for update: the loaded model instance
        foreach ($operations as $index => $operation) {
            $validated = $this->validateNestedOperation($operation, $index);
            if ($validated instanceof \Illuminate\Http\JsonResponse) {
                return $validated;
            }
            $validatedPerOp[$index] = $validated;

            $authResult = $this->authorizeNestedOperation($operation, $validated, $index);
            if ($authResult instanceof \Illuminate\Http\JsonResponse) {
                return $authResult;
            }
            $authResults[$index] = $authResult;
        }

        $results = $this->executeNestedOperations($operations, $validatedPerOp, $authResults);
        return response()->json(['results' => $results]);
    }

    /**
     * Validate request structure: operations present, array, each has model, action, data; id required for update.
     * Returns the operations array or returns a 422 JsonResponse.
     */
    protected function validateNestedStructure(Request $request)
    {
        $data = $request->all();
        if (!isset($data['operations']) || !is_array($data['operations'])) {
            return response()->json([
                'message' => 'The operations field is required and must be an array.',
                'errors' => ['operations' => ['The operations field is required and must be an array.']],
            ], 422);
        }
        $operations = $data['operations'];
        foreach ($operations as $index => $op) {
            if (!is_array($op)) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index => ['Each operation must be an object.']],
                ], 422);
            }
            if (empty($op['model']) || !is_string($op['model'])) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index . '.model' => ['The model field is required.']],
                ], 422);
            }
            if (empty($op['action']) || !in_array($op['action'], ['create', 'update'])) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index . '.action' => ['The action must be create or update.']],
                ], 422);
            }
            if (!isset($op['data']) || !is_array($op['data'])) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index . '.data' => ['The data field is required and must be an object.']],
                ], 422);
            }
            if (($op['action'] ?? '') === 'update') {
                if (!array_key_exists('id', $op)) {
                    return response()->json([
                        'message' => 'Invalid structure.',
                        'errors' => ['operations.' . $index . '.id' => ['The id field is required for update operations.']],
                    ], 422);
                }
            }
        }
        return $operations;
    }

    /**
     * Validate a single operation's data using the model's validation.
     *
     * Uses the legacy validateStore/validateUpdate when the model has $validationRulesStore/$validationRulesUpdate,
     * otherwise uses the new policy-driven validateForAction with forbidden field checking.
     *
     * Returns validated array or a 422/403 JsonResponse.
     */
    protected function validateNestedOperation(array $operation, int $index)
    {
        $slug = $operation['model'];
        if (!isset(config('rhino.models')[$slug])) {
            return response()->json([
                'message' => 'Unknown model.',
                'errors' => ['operations.' . $index . '.model' => ['The model "' . $slug . '" does not exist.']],
            ], 422);
        }
        $this->resolveModelClass($slug);
        $modelClass = $this->modelClass;

        // Cross-operation references ("$0.id") are resolved at execution time —
        // pull them out so format rules don't reject the placeholder strings,
        // and reject references that don't point at an EARLIER operation.
        $references = [];
        $plainData = [];
        foreach ($operation['data'] as $field => $value) {
            if (is_string($value) && preg_match('/^\$(\d+)\.[A-Za-z0-9_]+$/', $value, $matches)) {
                if ((int) $matches[1] >= $index) {
                    return response()->json([
                        'message' => 'Invalid reference.',
                        'errors' => ['operations.' . $index . '.data.' . $field => ['References must point to an earlier operation.']],
                    ], 422);
                }
                $references[$field] = $value;
            } else {
                $plainData[$field] = $value;
            }
        }

        $subRequest = Request::create('', 'POST', $plainData, [], [], [], []);
        $fullRequest = Request::create('', 'POST', $operation['data'], [], [], [], []);

        // Legacy path: model has $validationRulesStore/$validationRulesUpdate
        if ($modelClass->hasLegacyRulesConfig()) {
            if ($operation['action'] === 'create') {
                $validator = $modelClass->validateStore($subRequest);
            } else {
                $validator = $modelClass->validateUpdate($subRequest);
            }
            if ($validator->fails()) {
                $errors = [];
                foreach ($validator->errors()->messages() as $key => $messages) {
                    $errors['operations.' . $index . '.data.' . $key] = $messages;
                }
                return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
            }
            return array_merge($validator->validated(), $references);
        }

        // New policy-driven path
        $user = auth('sanctum')->user();
        $action = $operation['action'] === 'create' ? 'create' : 'update';
        $permittedFields = $this->resolvePermittedFields($user, $action);

        // Check for forbidden fields → 403
        $forbidden = $modelClass->findForbiddenFields($fullRequest, $permittedFields);
        if (!empty($forbidden)) {
            return response()->json([
                'message' => 'You are not allowed to set the following field(s): ' . implode(', ', $forbidden),
            ], 403);
        }

        $validator = $modelClass->validateForAction($subRequest, $permittedFields, $action === 'create' ? 'store' : 'update', array_keys($references));
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors['operations.' . $index . '.data.' . $key] = $messages;
            }
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        return array_merge($validator->validated(), $references);
    }

    /**
     * Authorize a single operation (create or update). For create returns null; for update returns the model instance.
     * Returns null or the model instance, or a 403/404 JsonResponse.
     */
    protected function authorizeNestedOperation(array $operation, array $validated, int $index)
    {
        $slug = $operation['model'];
        $this->resolveModelClass($slug);
        $modelClass = $this->modelClass;
        $user = auth('sanctum')->user();

        if ($operation['action'] === 'create') {
            Gate::forUser($user)->authorize('create', $modelClass);
            return null;
        }

        $query = QueryBuilder::for($modelClass::class)->where('id', $operation['id']);
        $this->applyOrganizationScope($query);
        try {
            $object = $query->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }
        Gate::forUser($user)->authorize('update', $object);
        return $object;
    }

    /**
     * Execute all operations inside a single DB transaction. Returns results array with model, action, id, data (full model).
     */
    protected function executeNestedOperations(array $operations, array $validatedPerOp, array $authResults): array
    {
        $results = [];
        DB::transaction(function () use ($operations, $validatedPerOp, $authResults, &$results) {
            foreach (array_keys($operations) as $index) {
                $op = $operations[$index];
                $validated = $validatedPerOp[$index];
                $modelOrNull = $authResults[$index];

                if ($op['action'] === 'create') {
                    $this->resolveModelClass($op['model']);
                    $data = $this->resolveNestedReferences($validated, $results);
                    $this->addOrganizationToData($data);
                    $model = $this->modelClass::create($data);
                    $results[] = [
                        'model' => $op['model'],
                        'action' => 'create',
                        'id' => $model->getKey(),
                        'data' => $this->serializeRecord($model),
                    ];
                } else {
                    $object = $modelOrNull;
                    $object->update($this->resolveNestedReferences($validated, $results));
                    $object->refresh();
                    $results[] = [
                        'model' => $op['model'],
                        'action' => 'update',
                        'id' => $object->getKey(),
                        'data' => $this->serializeRecord($object),
                    ];
                }
            }
        });
        return $results;
    }

    /**
     * Replace "$N.field" reference strings with values from earlier operation
     * results (e.g. "$0.id" → the id created by operation 0). Reference indices
     * were validated upfront in validateNestedOperation().
     */
    protected function resolveNestedReferences(array $data, array $results): array
    {
        foreach ($data as $field => $value) {
            if (is_string($value) && preg_match('/^\$(\d+)\.([A-Za-z0-9_]+)$/', $value, $matches)) {
                $result = $results[(int) $matches[1]] ?? null;
                $data[$field] = $matches[2] === 'id'
                    ? ($result['id'] ?? null)
                    : ($result['data'][$matches[2]] ?? null);
            }
        }

        return $data;
    }
}
