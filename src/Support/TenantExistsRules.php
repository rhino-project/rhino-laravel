<?php

namespace Rhino\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Cross-tenant `exists:` rule scoping.
 *
 * In tenant context every `exists:` rule in a validation ruleset is rewritten
 * so the referenced record must belong to the current organization — directly
 * when the referenced table carries an `organization_id`, or through a walked
 * foreign-key chain when it reaches its organization via a relationship
 * (`comments.task_id → tasks.project_id → projects.organization_id`).
 *
 * This lives in its own class because two entry points need the exact same
 * behavior: the model-level rules in {@see \Rhino\Traits\HasValidation} and the
 * request classes in {@see \Rhino\Http\Requests\ResourceRequest}. Re-deriving a
 * simpler `,organization_id,N` append in either place would silently un-scope
 * every indirectly-owned model.
 */
final class TenantExistsRules
{
    /**
     * Table => whether it has an organization_id column.
     *
     * @var array<string, bool>
     */
    private static array $orgColumnCache = [];

    /**
     * Table => FK chain to an org-scoped ancestor (or null when there is none).
     *
     * @var array<string, array<int, array{local_column: string, foreign_table: string, foreign_column: string}>|null>
     */
    private static array $fkChainCache = [];

    /**
     * In tenant context, scope any `exists:` rules targeting org-scoped tables
     * so that the referenced record must belong to the given organization.
     *
     * Direct: "exists:blogs,id" → "exists:blogs,id,organization_id,3"
     * Indirect: "exists:blog_posts,id" → Rule::exists with subquery through FK chain
     *
     * `organization_id` is removed from the ruleset entirely: in tenant context
     * it is framework-managed, never client-supplied.
     *
     * A null/absent organization means no tenant context — the rules are
     * returned untouched.
     *
     * @param  array  $rules
     * @param  mixed  $organization
     * @return array
     */
    public static function scope(array $rules, $organization): array
    {
        if (! $organization) {
            return $rules;
        }

        // In tenant context, organization_id is managed by the framework — remove from validation.
        unset($rules['organization_id']);

        $orgId = $organization->id;

        foreach ($rules as $field => &$ruleSet) {
            // A rule value may also be a single Rule object or a closure —
            // neither can carry an `exists:` string, so leave it alone.
            if (! is_string($ruleSet) && ! is_array($ruleSet)) {
                continue;
            }

            $ruleSet = self::scopeInRuleSet($ruleSet, $orgId);
        }

        return $rules;
    }

    /**
     * Process a rule set (string or array) and scope any exists: rules to the current org.
     * Returns string if no Rule objects were needed, array otherwise.
     */
    public static function scopeInRuleSet(string|array $ruleSet, int|string $orgId): string|array
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
            if (self::tableHasOrganizationId($table)) {
                $result[] = $part . ',organization_id,' . $orgId;
                continue;
            }

            // Indirect: walk FK chain to find org-scoped ancestor
            $chain = self::findOrganizationFkChain($table);
            if ($chain !== null) {
                $needsObjectRule = true;
                $result[] = self::buildScopedExistsRule($table, $column, $orgId, $chain);
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
    public static function findOrganizationFkChain(string $table): ?array
    {
        if (array_key_exists($table, self::$fkChainCache)) {
            return self::$fkChainCache[$table];
        }

        $chain = self::walkFkChain($table, 5, []);
        self::$fkChainCache[$table] = $chain;

        return $chain;
    }

    public static function walkFkChain(string $table, int $maxDepth, array $visited): ?array
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

            if (self::tableHasOrganizationId($foreignTable)) {
                return [['local_column' => $localColumn, 'foreign_table' => $foreignTable, 'foreign_column' => $foreignColumn]];
            }

            $deeper = self::walkFkChain($foreignTable, $maxDepth - 1, $visited);
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
    public static function buildScopedExistsRule(string $table, string $column, int|string $orgId, array $chain): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, $column)->where(function ($query) use ($orgId, $chain) {
            self::applyFkChainScope($query, $orgId, $chain, 0);
        });
    }

    public static function applyFkChainScope($query, int|string $orgId, array $chain, int $index): void
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
                self::applyFkChainScope($sub, $orgId, $chain, $index + 1);
            });
        }
    }

    public static function tableHasOrganizationId(string $table): bool
    {
        if (!isset(self::$orgColumnCache[$table])) {
            self::$orgColumnCache[$table] = Schema::hasColumn($table, 'organization_id');
        }

        return self::$orgColumnCache[$table];
    }

    /**
     * Forget the schema lookups. Intended for tests that swap the schema
     * between cases; production code never needs it (the caches describe the
     * database structure, which does not change at runtime).
     */
    public static function flushCaches(): void
    {
        self::$orgColumnCache = [];
        self::$fkChainCache = [];
    }
}
