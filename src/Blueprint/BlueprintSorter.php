<?php

namespace Rhino\Blueprint;

/**
 * Orders blueprints so that a referenced model's table is created before any
 * model whose migration adds a foreign key to it (parents before children).
 *
 * Foreign keys are taken from `foreignId` columns that carry a `foreignModel`
 * mapping to another model in the same generation set. References that impose no
 * ordering are ignored:
 *   - self-references (a model's FK to its own table — created in one migration),
 *   - references to models NOT in this set (e.g. `Organization`/`User`, whose
 *     tables are created by `rhino:install`, not the blueprint run).
 *
 * The sort uses Kahn's algorithm with a stable tie-break: among models with no
 * remaining unmet dependency, the one earliest in the input order wins. The
 * input is already alphabetical (by file name), so the output stays alphabetical
 * wherever relationships don't force a reorder — deterministic and minimal-churn.
 *
 * A circular FK dependency (A → B → A) has no linear migration order. Such
 * models can't be ordered cleanly, so the cycle is broken deterministically
 * (earliest-input model first), the run still produces a best-effort order, and
 * the involved models are reported via {@see cycles()} so the caller can warn
 * (one side should be a nullable / deferred FK).
 */
class BlueprintSorter
{
    /**
     * Model names whose circular dependency had to be broken during the last
     * {@see sort()} call (empty when the graph is acyclic).
     *
     * @var array<int, string>
     */
    protected array $cycles = [];

    /**
     * Re-order blueprints into a valid migration sequence (parents first).
     *
     * @param array<int, array> $blueprints Normalized blueprints (each with a
     *                                       'model' name and 'columns').
     * @return array<int, array> The blueprints, re-ordered.
     */
    public function sort(array $blueprints): array
    {
        $this->cycles = [];

        if (count($blueprints) < 2) {
            return array_values($blueprints);
        }

        // Index by model name; ignore duplicates (first wins) defensively.
        $byModel = [];
        foreach ($blueprints as $bp) {
            $model = $bp['model'] ?? null;
            if ($model !== null && !isset($byModel[$model])) {
                $byModel[$model] = $bp;
            }
        }

        // Build the dependency graph. dependents[ref] = models that reference ref;
        // indegree[model] = number of (distinct, in-set, non-self) models it must
        // be created after.
        $dependents = [];
        $indegree = [];
        foreach ($byModel as $model => $bp) {
            $dependents[$model] = [];
            $indegree[$model] = 0;
        }

        foreach ($byModel as $model => $bp) {
            $seen = [];
            foreach ($this->dependencyModels($bp) as $ref) {
                if ($ref === $model || !isset($byModel[$ref]) || isset($seen[$ref])) {
                    continue;
                }
                $seen[$ref] = true;
                $dependents[$ref][] = $model;
                $indegree[$model]++;
            }
        }

        // The order in which models appear in the (already-sorted) input, used as
        // the stable tie-break for both normal picks and cycle-breaking.
        $inputOrder = array_keys($byModel);

        // Record the models that actually participate in a cycle (reachable from
        // themselves through the dependency edges) — reported in input order so
        // the caller can warn about the full cycle, not just the broken edge.
        foreach ($inputOrder as $model) {
            if ($this->reachableFromSelf($model, $dependents)) {
                $this->cycles[] = $model;
            }
        }

        $ordered = [];
        $resolved = [];

        while (count($ordered) < count($byModel)) {
            $pick = null;
            foreach ($inputOrder as $model) {
                if (!isset($resolved[$model]) && $indegree[$model] === 0) {
                    $pick = $model;
                    break;
                }
            }

            if ($pick === null) {
                // No model has its dependencies satisfied → a cycle blocks the
                // graph. Break it deterministically by force-emitting the
                // earliest-input unresolved model so the run still produces a
                // best-effort order (the cycle itself is reported via cycles()).
                foreach ($inputOrder as $model) {
                    if (!isset($resolved[$model])) {
                        $pick = $model;
                        break;
                    }
                }
            }

            $ordered[] = $byModel[$pick];
            $resolved[$pick] = true;
            foreach ($dependents[$pick] as $child) {
                $indegree[$child]--;
            }
        }

        return $ordered;
    }

    /**
     * Model names involved in a circular foreign-key dependency during the last
     * {@see sort()} (empty if the dependency graph was acyclic).
     *
     * @return array<int, string>
     */
    public function cycles(): array
    {
        return $this->cycles;
    }

    /**
     * Whether $start can reach itself by following dependency edges — i.e. it
     * participates in a circular foreign-key dependency. $adj maps a model to the
     * models that reference it (dependents).
     *
     * @param array<string, array<int, string>> $adj
     */
    protected function reachableFromSelf(string $start, array $adj): bool
    {
        $stack = $adj[$start] ?? [];
        $visited = [];
        while ($stack) {
            $node = array_pop($stack);
            if ($node === $start) {
                return true;
            }
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;
            foreach ($adj[$node] ?? [] as $next) {
                $stack[] = $next;
            }
        }
        return false;
    }

    /**
     * The model names this blueprint's migration adds foreign keys to, taken
     * from its `foreignId` columns that carry a `foreignModel`.
     *
     * @param array $blueprint
     * @return array<int, string>
     */
    protected function dependencyModels(array $blueprint): array
    {
        $refs = [];
        foreach ($blueprint['columns'] ?? [] as $col) {
            if (($col['type'] ?? null) === 'foreignId' && !empty($col['foreignModel'])) {
                $refs[] = $col['foreignModel'];
            }
        }
        return $refs;
    }
}
