<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\BlueprintSorter;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive coverage for dependency-aware migration ordering: parents (referenced
 * tables) must be emitted before children (the models that foreign-key to them).
 */
class BlueprintSorterTest extends TestCase
{
    protected BlueprintSorter $sorter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sorter = new BlueprintSorter();
    }

    /**
     * Build a blueprint with foreignId columns to each model in $fkModels, plus
     * any extra raw columns.
     */
    private function bp(string $model, array $fkModels = [], array $extraColumns = []): array
    {
        $columns = [];
        foreach ($fkModels as $fk) {
            $columns[] = [
                'name' => strtolower($fk) . '_id',
                'type' => 'foreignId',
                'foreignModel' => $fk,
            ];
        }
        foreach ($extraColumns as $col) {
            $columns[] = $col;
        }

        return ['model' => $model, 'table' => strtolower($model) . 's', 'columns' => $columns];
    }

    /** @param array<int,array> $blueprints @return array<int,string> */
    private function names(array $blueprints): array
    {
        return array_map(fn ($b) => $b['model'], $blueprints);
    }

    private function assertBefore(string $a, string $b, array $ordered): void
    {
        $ia = array_search($a, $ordered, true);
        $ib = array_search($b, $ordered, true);
        $this->assertNotFalse($ia, "$a missing from output");
        $this->assertNotFalse($ib, "$b missing from output");
        $this->assertLessThan($ib, $ia, "Expected $a (parent) before $b (child)");
    }

    /** Output must always contain exactly the input models — never drop or duplicate. */
    private function assertSameModelSet(array $input, array $output): void
    {
        $in = $this->names($input);
        $out = $this->names($output);
        sort($in);
        sort($out);
        $this->assertSame($in, $out, 'Output model set must equal input model set');
    }

    // ── degenerate inputs ────────────────────────────────────────────────

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame([], $this->sorter->sort([]));
        $this->assertSame([], $this->sorter->cycles());
    }

    public function test_single_model_unchanged(): void
    {
        $in = [$this->bp('Post')];
        $out = $this->sorter->sort($in);
        $this->assertSame(['Post'], $this->names($out));
        $this->assertSame([], $this->sorter->cycles());
    }

    // ── independents: stable order preserved ─────────────────────────────

    public function test_independent_models_keep_input_order(): void
    {
        $in = [$this->bp('Apple'), $this->bp('Banana'), $this->bp('Cherry')];
        $out = $this->sorter->sort($in);
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $this->names($out));
        $this->assertSame([], $this->sorter->cycles());
    }

    // ── linear chain ─────────────────────────────────────────────────────

    public function test_linear_chain_orders_parents_first(): void
    {
        // Comment → Post → Blog (declared child-first, as `sort()` on files would
        // not necessarily group them).
        $in = [
            $this->bp('Comment', ['Post']),
            $this->bp('Post', ['Blog']),
            $this->bp('Blog'),
        ];
        $out = $this->names($this->sorter->sort($in));
        $this->assertSame(['Blog', 'Post', 'Comment'], $out);
        $this->assertSame([], $this->sorter->cycles());
    }

    public function test_forward_reference_child_before_parent_in_input(): void
    {
        // Child listed before its parent: parent must still end up first.
        $in = [$this->bp('Comment', ['Post']), $this->bp('Post')];
        $out = $this->names($this->sorter->sort($in));
        $this->assertSame(['Post', 'Comment'], $out);
        $this->assertSame([], $this->sorter->cycles());
    }

    // ── diamond ──────────────────────────────────────────────────────────

    public function test_diamond_dependency(): void
    {
        // D → B, D → C, B → A, C → A.  A first, D last; B/C in stable order.
        $in = [
            $this->bp('D', ['B', 'C']),
            $this->bp('C', ['A']),
            $this->bp('B', ['A']),
            $this->bp('A'),
        ];
        $out = $this->names($this->sorter->sort($in));
        $this->assertBefore('A', 'B', $out);
        $this->assertBefore('A', 'C', $out);
        $this->assertBefore('B', 'D', $out);
        $this->assertBefore('C', 'D', $out);
        $this->assertSame('A', $out[0]);
        $this->assertSame('D', $out[3]);
        $this->assertSame([], $this->sorter->cycles());
    }

    // ── mixed independents + chains ──────────────────────────────────────

    public function test_mixed_independents_and_chains(): void
    {
        $in = [
            $this->bp('Alpha'),                 // independent
            $this->bp('Comment', ['Post']),     // child
            $this->bp('Post', ['Blog']),        // middle
            $this->bp('Zeta'),                  // independent
            $this->bp('Blog'),                  // root
        ];
        $out = $this->names($this->sorter->sort($in));
        $this->assertBefore('Blog', 'Post', $out);
        $this->assertBefore('Post', 'Comment', $out);
        // Independents keep their relative input order.
        $this->assertBefore('Alpha', 'Zeta', $out);
        $this->assertSameModelSet($in, $this->sorter->sort($in));
    }

    // ── references that impose NO ordering ───────────────────────────────

    public function test_self_reference_is_not_a_dependency_or_cycle(): void
    {
        // Category.parent_id → Category (same table) — no reorder, no cycle.
        $in = [$this->bp('Category', ['Category']), $this->bp('Tag')];
        $out = $this->names($this->sorter->sort($in));
        $this->assertSame(['Category', 'Tag'], $out);
        $this->assertSame([], $this->sorter->cycles());
    }

    public function test_reference_to_model_outside_the_set_is_ignored(): void
    {
        // Post → Organization, but Organization is not in this generation set
        // (created by rhino:install). No reorder, no cycle.
        $in = [$this->bp('Post', ['Organization']), $this->bp('Comment')];
        $out = $this->names($this->sorter->sort($in));
        $this->assertSame(['Post', 'Comment'], $out);
        $this->assertSame([], $this->sorter->cycles());
    }

    public function test_non_foreignId_column_with_foreignModel_does_not_order(): void
    {
        // A stray foreignModel on a non-FK column must not create a dependency.
        $in = [
            $this->bp('Beta', [], [['name' => 'x', 'type' => 'string', 'foreignModel' => 'Alpha']]),
            $this->bp('Alpha'),
        ];
        $out = $this->names($this->sorter->sort($in));
        $this->assertSame(['Beta', 'Alpha'], $out); // unchanged
        $this->assertSame([], $this->sorter->cycles());
    }

    public function test_duplicate_fk_to_same_parent_counted_once(): void
    {
        // Two FK columns to the same parent must not double-count indegree.
        $in = [
            $this->bp('Match', ['Team', 'Team']), // home_team + away_team → Team
            $this->bp('Team'),
        ];
        $out = $this->names($this->sorter->sort($in));
        $this->assertSame(['Team', 'Match'], $out);
        $this->assertSame([], $this->sorter->cycles());
    }

    // ── cycles ───────────────────────────────────────────────────────────

    public function test_direct_cycle_is_detected_and_still_returns_all(): void
    {
        $in = [$this->bp('A', ['B']), $this->bp('B', ['A'])];
        $out = $this->sorter->sort($in);
        $this->assertSameModelSet($in, $out);            // nothing dropped
        $this->assertCount(2, $out);
        $cycles = $this->sorter->cycles();
        $this->assertContains('A', $cycles);
        $this->assertContains('B', $cycles);
    }

    public function test_three_node_cycle_is_detected(): void
    {
        $in = [$this->bp('A', ['B']), $this->bp('B', ['C']), $this->bp('C', ['A'])];
        $out = $this->sorter->sort($in);
        $this->assertSameModelSet($in, $out);
        $this->assertNotEmpty($this->sorter->cycles());
    }

    public function test_cycle_with_downstream_dependent(): void
    {
        // A ↔ B cycle; C → A depends on the cycle. C must come after the cycle
        // is broken, and C itself is not a cycle member.
        $in = [
            $this->bp('A', ['B']),
            $this->bp('B', ['A']),
            $this->bp('C', ['A']),
        ];
        $out = $this->names($this->sorter->sort($in));
        $this->assertSameModelSet($in, $this->sorter->sort($in));
        $this->assertBefore('A', 'C', $out);
        $this->assertNotContains('C', $this->sorter->cycles());
    }

    public function test_two_independent_cycles_both_detected(): void
    {
        $in = [
            $this->bp('A', ['B']), $this->bp('B', ['A']),
            $this->bp('X', ['Y']), $this->bp('Y', ['X']),
        ];
        $this->sorter->sort($in);
        $cycles = $this->sorter->cycles();
        foreach (['A', 'B', 'X', 'Y'] as $m) {
            $this->assertContains($m, $cycles);
        }
    }

    // ── determinism ──────────────────────────────────────────────────────

    public function test_sort_is_idempotent(): void
    {
        $in = [
            $this->bp('Comment', ['Post']),
            $this->bp('Post', ['Blog']),
            $this->bp('Blog'),
            $this->bp('Tag'),
        ];
        $once = $this->sorter->sort($in);
        $twice = $this->sorter->sort($once);
        $this->assertSame($this->names($once), $this->names($twice));
    }

    public function test_cycles_reset_between_runs(): void
    {
        $this->sorter->sort([$this->bp('A', ['B']), $this->bp('B', ['A'])]);
        $this->assertNotEmpty($this->sorter->cycles());
        // A subsequent acyclic run must report no cycles.
        $this->sorter->sort([$this->bp('Blog'), $this->bp('Post', ['Blog'])]);
        $this->assertSame([], $this->sorter->cycles());
    }
}
