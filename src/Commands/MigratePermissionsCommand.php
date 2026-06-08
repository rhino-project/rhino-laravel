<?php

namespace Rhino\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lift per-user permissions into the shared org role layer.
 *
 * For each (organization, role) group, the literal intersection of every user's
 * `user_roles.permissions` becomes the `org_role_permissions` row (the shared
 * role layer). Each user's row is then reduced to only its delta
 * (`granted_permissions = permissions − roleLayer`) and its legacy `permissions`
 * is cleared. Effective permissions are preserved exactly (the intersection is a
 * subset of every user's set, so nothing is gained or lost).
 *
 * Safe & idempotent:
 *   - Dry-run by default; pass --apply to write.
 *   - Groups that already have an org_role_permissions row are skipped (assumed
 *     already managed by the role layer).
 *   - After a run the legacy permissions are empty, so a second run is a no-op.
 *   - Non-tenant (NULL organization) rows are left untouched.
 */
class MigratePermissionsCommand extends Command
{
    protected $signature = 'rhino:permissions-migrate
                            {--apply : Write the changes (default is a dry-run preview)}';

    protected $description = 'Lift per-user user_roles.permissions into the shared org_role_permissions role layer, reducing each user row to its delta';

    public function handle(): int
    {
        if (!Schema::hasTable('user_roles') || !Schema::hasTable('org_role_permissions')) {
            $this->error('Required tables (user_roles, org_role_permissions) are missing. Run migrations first.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        // Distinct (organization, role) groups that have an org context.
        $groups = DB::table('user_roles')
            ->select('organization_id', 'role_id')
            ->whereNotNull('organization_id')
            ->whereNotNull('role_id')
            ->distinct()
            ->get();

        $groupsMigrated = 0;
        $rowsReduced = 0;
        $skippedExisting = 0;

        foreach ($groups as $group) {
            $rows = DB::table('user_roles')
                ->where('organization_id', $group->organization_id)
                ->where('role_id', $group->role_id)
                ->get(['id', 'permissions', 'granted_permissions']);

            // Only rows that still carry legacy permissions need migrating.
            $withLegacy = $rows->filter(fn ($r) => !empty($this->decode($r->permissions)));

            if ($withLegacy->isEmpty()) {
                continue;
            }

            // A pre-existing role layer means the group is already managed.
            $existing = DB::table('org_role_permissions')
                ->where('organization_id', $group->organization_id)
                ->where('role_id', $group->role_id)
                ->exists();

            if ($existing) {
                $skippedExisting++;
                continue;
            }

            // Role layer = literal intersection of every legacy permission set.
            $sets = $withLegacy->map(fn ($r) => $this->decode($r->permissions))->all();
            $roleLayer = array_values(array_intersect(...$sets));

            $this->line(sprintf(
                'org=%s role=%s → role layer [%s] (%d user rows)',
                $group->organization_id,
                $group->role_id,
                implode(', ', $roleLayer),
                $withLegacy->count()
            ));

            if ($apply) {
                DB::table('org_role_permissions')->insert([
                    'organization_id' => $group->organization_id,
                    'role_id' => $group->role_id,
                    'permissions' => json_encode($roleLayer),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($withLegacy as $r) {
                    $legacy = $this->decode($r->permissions);
                    $existingGrants = $this->decode($r->granted_permissions);
                    $delta = array_values(array_unique(array_merge(
                        array_diff($legacy, $roleLayer),
                        $existingGrants
                    )));

                    DB::table('user_roles')->where('id', $r->id)->update([
                        'permissions' => json_encode([]),
                        'granted_permissions' => json_encode($delta),
                        'updated_at' => now(),
                    ]);
                }
            }

            $groupsMigrated++;
            $rowsReduced += $withLegacy->count();
        }

        $verb = $apply ? 'Migrated' : 'Would migrate';
        $this->info(sprintf(
            '%s %d (org, role) group(s); %d user row(s) reduced to deltas.%s',
            $verb,
            $groupsMigrated,
            $rowsReduced,
            $skippedExisting ? " Skipped {$skippedExisting} group(s) with an existing role layer." : ''
        ));

        if (!$apply && $groupsMigrated > 0) {
            $this->comment('Dry-run only. Re-run with --apply to write these changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Decode a permissions column value into a clean list of strings.
     *
     * @return string[]
     */
    private function decode($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return [];
    }
}
