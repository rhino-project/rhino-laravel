<?php

namespace Rhino\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Organization scoping logic, extracted from GlobalController so the SAME
 * tenant-safe scoping can be applied to any model outside a CRUD request
 * (dashboards, reports, jobs, commands) via Rhino::query().
 *
 * Every method here is parameterized by the model + organization instead of
 * reading $this->modelClass / request(), so the trait is host-agnostic.
 */
trait ScopesToOrganization
{
    /**
     * Cache for auto-detected organization relationship paths, keyed by model class.
     */
    protected static array $organizationPathCache = [];

    /**
     * Resolve the ambient model for the backward-compatible (unparameterized)
     * call forms used by GlobalController, which read $this->modelClass.
     */
    private function resolveContextModel(): Model
    {
        $model = property_exists($this, 'modelClass') ? $this->modelClass : null;

        if ($model instanceof Model) {
            return $model;
        }

        if (is_string($model) && class_exists($model)) {
            return app()->make($model);
        }

        throw new \LogicException('No ambient model available; pass a Model explicitly to the scoping method.');
    }

    /**
     * Apply organization scope to a query for the given model + organization.
     *
     * Same resolution order as the original GlobalController::applyOrganizationScope():
     * Organization-is-self -> scopeForOrganization -> organization_id column ->
     * $owner path ('none' opts out) -> auto-detected relationship path.
     *
     * If $organization is null, no scoping is applied — the caller (resolver)
     * decides fail-closed behavior, NOT this method.
     */
    protected function scopeQueryToOrganization($query, Model $model, $organization): void
    {
        if (! $organization) {
            return;
        }

        // When the resource being queried IS the Organization model, restrict to the current organization only
        if (get_class($organization) === get_class($model)) {
            $query->where($organization->getKeyName(), $organization->getKey());
            return;
        }

        // Check if model has a scopeForOrganization method
        if (method_exists($model, 'scopeForOrganization')) {
            $query->forOrganization($organization);
            return;
        }

        // Check if model has organization_id column (direct relationship)
        if (in_array('organization_id', $model->getFillable())) {
            $query->where('organization_id', $organization->id);
            return;
        }

        // Check if model has explicit $owner property defined
        if (property_exists($model, 'owner') && !empty($model::$owner)) {
            // 'none' means the model explicitly opts out of organization scoping
            if ($model::$owner === 'none') {
                return;
            }

            $this->applyOrganizationScopeThroughRelationship($query, $organization, $model, $model::$owner);
            return;
        }

        // Fallback: Auto-detect organization relationship by traversing BelongsTo chains
        $organizationPath = $this->findOrganizationRelationshipPath($model);

        if ($organizationPath) {
            $this->applyOrganizationScopeThroughRelationship($query, $organization, $model, $organizationPath);
        }
        // If no organization relationship found, model is global (available to all orgs)
    }

    /**
     * True when the model participates in organization scoping in any form.
     *
     * Used for fail-closed: a model that IS org-scoped must never be queried
     * without an organization context.
     */
    public function isOrganizationScoped(Model $model): bool
    {
        if (method_exists($model, 'scopeForOrganization')) {
            return true;
        }

        if (in_array('organization_id', $model->getFillable())) {
            return true;
        }

        if (property_exists($model, 'owner') && !empty($model::$owner) && $model::$owner !== 'none') {
            return true;
        }

        if (property_exists($model, 'owner') && $model::$owner === 'none') {
            // Explicit opt-out: NOT organization scoped.
            return false;
        }

        return $this->findOrganizationRelationshipPath($model) !== null;
    }

    /**
     * Find the relationship path to Organization model.
     *
     * Auto-detects the path by introspecting BelongsTo relationships and
     * following them until an Organization (or a model with organization_id)
     * is found. Results are cached per model class.
     *
     * @return string|null The dot-notation relationship path, or null if none found.
     */
    protected function findOrganizationRelationshipPath(Model $model): ?string
    {
        $modelClass = get_class($model);

        // Return cached result (including null)
        if (array_key_exists($modelClass, static::$organizationPathCache)) {
            return static::$organizationPathCache[$modelClass];
        }

        // Check for direct organization relationship
        if (method_exists($modelClass, 'organization')) {
            return static::$organizationPathCache[$modelClass] = 'organization';
        }

        // Check for organizations (many-to-many or has-many)
        if (method_exists($modelClass, 'organizations')) {
            return static::$organizationPathCache[$modelClass] = 'organizations';
        }

        // Auto-detect by traversing BelongsTo relationships
        $result = $this->discoverOrganizationPath($modelClass, [], 3);

        static::$organizationPathCache[$modelClass] = $result;

        return $result;
    }

    /**
     * Recursively discover the relationship path from $modelClass to Organization
     * by introspecting BelongsTo relationships.
     *
     * @param  string   $modelClass  Fully-qualified model class name
     * @param  array    $visited     Classes already visited (cycle prevention)
     * @param  int      $maxDepth    Remaining traversal depth
     * @return string|null Dot-notation path, or null if no path found
     */
    protected function discoverOrganizationPath(string $modelClass, array $visited, int $maxDepth): ?string
    {
        if ($maxDepth <= 0 || in_array($modelClass, $visited)) {
            return null;
        }

        $visited[] = $modelClass;
        $belongsToRelations = $this->getBelongsToRelationships($modelClass);
        $matchingPaths = [];

        foreach ($belongsToRelations as $methodName => $relatedClass) {
            // Direct match: related model IS Organization
            if ($relatedClass === \App\Models\Organization::class) {
                $matchingPaths[] = $methodName;
                continue;
            }

            $relatedInstance = new $relatedClass;

            // Related model has organization_id in fillable
            if (in_array('organization_id', $relatedInstance->getFillable())) {
                $matchingPaths[] = $methodName;
                continue;
            }

            // Related model uses BelongsToOrganization trait
            if (in_array(\Rhino\Traits\BelongsToOrganization::class, class_uses_recursive($relatedClass))) {
                $matchingPaths[] = $methodName;
                continue;
            }

            // Related model has organization() method
            if (method_exists($relatedClass, 'organization')) {
                $matchingPaths[] = $methodName;
                continue;
            }

            // Related model has explicit $owner — compose the path
            if (property_exists($relatedClass, 'owner') && !empty($relatedClass::$owner)) {
                $matchingPaths[] = $methodName . '.' . $relatedClass::$owner;
                continue;
            }

            // Recurse into related model's BelongsTo relationships
            $subPath = $this->discoverOrganizationPath($relatedClass, $visited, $maxDepth - 1);
            if ($subPath !== null) {
                $matchingPaths[] = $methodName . '.' . $subPath;
            }
        }

        if (empty($matchingPaths)) {
            return null;
        }

        if (count($matchingPaths) > 1) {
            Log::debug("Rhino: Model {$modelClass} has multiple BelongsTo paths to Organization. Using '{$matchingPaths[0]}'. Set \$owner explicitly to override.", [
                'paths' => $matchingPaths,
            ]);
        }

        return $matchingPaths[0];
    }

    /**
     * Use Reflection to find all BelongsTo relationships on a model class.
     *
     * @param  string $modelClass Fully-qualified model class name
     * @return array<string, string> Map of method name => related model class
     */
    protected function getBelongsToRelationships(string $modelClass): array
    {
        $instance = new $modelClass;

        if (!$instance->getConnection()) {
            $instance->setConnection($instance->getConnectionName() ?: config('database.default'));
        }

        $reflection = new \ReflectionClass($instance);
        $belongsTo = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip methods from Eloquent base classes
            if ($method->class === \Illuminate\Database\Eloquent\Model::class) {
                continue;
            }

            // Skip methods that require parameters
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $name = $method->getName();

            // Skip non-relationship method patterns
            if (str_starts_with($name, 'get')
                || str_starts_with($name, 'set')
                || str_starts_with($name, 'scope')
                || str_starts_with($name, 'boot')
                || str_starts_with($name, '__')
                || str_starts_with($name, 'is')
                || str_starts_with($name, 'to')
                || $name === 'organization'
                || $name === 'organizations'
            ) {
                continue;
            }

            // Fast filter using return type hint when available
            $returnType = $method->getReturnType();
            if ($returnType instanceof \ReflectionNamedType) {
                $typeName = $returnType->getName();
                if (class_exists($typeName)
                    && $typeName !== BelongsTo::class
                    && !is_subclass_of($typeName, BelongsTo::class)
                    && $typeName !== 'mixed'
                ) {
                    continue;
                }
            }

            try {
                $result = $instance->{$name}();
                if ($result instanceof BelongsTo) {
                    $belongsTo[$name] = get_class($result->getRelated());
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $belongsTo;
    }

    /**
     * Apply organization scope through a relationship path.
     * Supports dot-separated paths (e.g., 'post.blog') for nested relationships.
     * Automatically traverses the path to find organization.
     */
    protected function applyOrganizationScopeThroughRelationship($query, $organization, $model, ?string $relationshipPath = null): void
    {
        // Backward-compatible call form: ($query, $organization, string $relationshipPath)
        // resolves the model from $this->modelClass (used by GlobalController).
        if ($relationshipPath === null) {
            $relationshipPath = $model;
            $model = $this->resolveContextModel();
        }
        if (! $model instanceof Model) {
            $model = app()->make($model);
        }

        // Handle dot-separated paths (e.g., 'post.blog')
        if (strpos($relationshipPath, '.') !== false) {
            // For nested paths, recursively build the path to organization
            $this->applyNestedOrganizationScope($query, $organization, $model, $relationshipPath);
            return;
        }

        // Single relationship path (non-nested)
        $modelInstance = $model;

        // Check if the relationship method exists
        if (!method_exists($modelInstance, $relationshipPath)) {
            return;
        }

        try {
            $relation = $modelInstance->$relationshipPath();
        } catch (\Exception $e) {
            return; // Skip if relationship can't be resolved
        }

        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            // Many-to-many relationship
            $query->whereHas($relationshipPath, function ($q) use ($organization) {
                $q->where('organizations.id', $organization->id);
            });
        } elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany ||
                  $relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            // Has-many or has-one - check if related model has organization_id
            $relatedModel = get_class($relation->getRelated());
            $relatedInstance = new $relatedModel;

            if (in_array('organization_id', $relatedInstance->getFillable())) {
                $query->whereHas($relationshipPath, function ($q) use ($organization) {
                    $q->where('organization_id', $organization->id);
                });
            } elseif (property_exists($relatedModel, 'owner') && !empty($relatedModel::$owner)) {
                // Related model has $owner property, recursively traverse
                $this->applyOrganizationScopeThroughRelationship($query, $organization, $model, $relationshipPath . '.' . $relatedModel::$owner);
            } elseif (method_exists($relatedModel, 'organization') || method_exists($relatedModel, 'organizations')) {
                // Related model has organization relationship, traverse further
                $query->whereHas($relationshipPath . '.organization', function ($q) use ($organization) {
                    $q->where('organizations.id', $organization->id);
                });
            }
        } elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            // Belongs-to relationship - check if related model is Organization
            $relatedModel = get_class($relation->getRelated());

            if ($relatedModel === \App\Models\Organization::class) {
                $query->where($relation->getForeignKeyName(), $organization->id);
            } else {
                // Related model might have organization relationship or $owner property
                $relatedInstance = new $relatedModel;
                if (in_array('organization_id', $relatedInstance->getFillable())) {
                    $query->whereHas($relationshipPath, function ($q) use ($organization) {
                        $q->where('organization_id', $organization->id);
                    });
                } elseif (property_exists($relatedModel, 'owner') && !empty($relatedModel::$owner)) {
                    // Related model has $owner property, recursively traverse
                    $this->applyOrganizationScopeThroughRelationship($query, $organization, $model, $relationshipPath . '.' . $relatedModel::$owner);
                }
            }
        }
    }

    /**
     * Build the full relationship path to organization by following $owner properties.
     * Returns the full dot-separated path (e.g., 'post.blog.organization').
     */
    protected function buildOrganizationPath($model, ?string $startPath = null): ?string
    {
        // Backward-compatible call form: buildOrganizationPath(string $startPath)
        // resolves the model from $this->modelClass (used by GlobalController).
        if ($startPath === null) {
            $startPath = $model;
            $model = $this->resolveContextModel();
        }
        if (! $model instanceof Model) {
            $model = app()->make($model);
        }

        $modelClass = get_class($model);
        $currentPath = $startPath;
        $visited = []; // Prevent infinite loops

        while (true) {
            // Get the last relationship name in the path
            $pathParts = explode('.', $currentPath);
            $lastPart = end($pathParts);

            // Check if we've visited this path before (infinite loop prevention)
            if (in_array($currentPath, $visited)) {
                return null;
            }
            $visited[] = $currentPath;

            // Traverse the path to get the related model
            $currentModel = $modelClass;
            $tempInstance = new $currentModel;

            foreach ($pathParts as $part) {
                if (!method_exists($tempInstance, $part)) {
                    return null;
                }
                try {
                    $relation = $tempInstance->$part();
                    if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        return null;
                    }
                    $currentModel = get_class($relation->getRelated());
                    $tempInstance = new $currentModel;
                } catch (\Exception $e) {
                    return null;
                }
            }

            // Check if we've reached Organization
            if ($currentModel === \App\Models\Organization::class) {
                return $currentPath;
            }

            // Check if current model has organization_id
            $currentInstance = new $currentModel;
            if (in_array('organization_id', $currentInstance->getFillable())) {
                return $currentPath . '.organization';
            }

            // Check if current model has $owner property
            if (property_exists($currentModel, 'owner') && !empty($currentModel::$owner)) {
                $currentPath .= '.' . $currentModel::$owner;
                continue;
            }

            // Check if current model has organization relationship
            if (method_exists($currentModel, 'organization')) {
                return $currentPath . '.organization';
            }

            // Can't find organization path
            return null;
        }
    }

    /**
     * Apply organization scope for nested relationship paths (e.g., 'post.blog').
     * Builds the full path to organization and applies whereHas.
     */
    protected function applyNestedOrganizationScope($query, $organization, $model, ?string $path = null): void
    {
        // Backward-compatible call form: ($query, $organization, string $path)
        // resolves the model from $this->modelClass (used by GlobalController).
        if ($path === null) {
            $path = $model;
            $model = $this->resolveContextModel();
        }
        if (! $model instanceof Model) {
            $model = app()->make($model);
        }

        $fullPath = $this->buildOrganizationPath($model, $path);

        if (!$fullPath) {
            return; // Couldn't build path to organization
        }

        // Check if the path ends with organization or organization_id
        if (str_ends_with($fullPath, '.organization') || str_ends_with($fullPath, '.organizations')) {
            $query->whereHas($fullPath, function ($q) use ($organization) {
                $q->where('organizations.id', $organization->id);
            });
        } else {
            // Path ends with a model that has organization_id
            $query->whereHas($fullPath, function ($q) use ($organization) {
                $q->where('organization_id', $organization->id);
            });
        }
    }
}
