<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // organization_id is nullable: non-tenant groups (e.g. admin/driver)
            // have no organization. Tenant-group rows still require an org.
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            // route_group scopes a membership to a group. NULL = wildcard
            // (member of every group), the back-compat default.
            $table->string('route_group')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();

            // Uniqueness keyed by (user, organization, role, route_group).
            // NULL values are treated as distinct by SQLite/MySQL unique
            // indexes, so we use a coalesced expression index (sentinel values)
            // to keep uniqueness holding for NULL org / NULL route_group rows.
            $table->unique(['user_id', 'organization_id', 'role_id', 'route_group']);
        });

        // SQLite (and MySQL) treat NULL as distinct in unique indexes, so the
        // composite index above does not prevent duplicate NULL-org / NULL-group
        // rows. Add an expression-based unique index that coalesces NULLs to a
        // sentinel so uniqueness holds across the matrix on both drivers.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX user_roles_unique_coalesced ON user_roles '
                . '(user_id, COALESCE(organization_id, 0), role_id, COALESCE(route_group, \'\'))'
            );
        } elseif ($driver === 'mysql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX user_roles_unique_coalesced ON user_roles '
                . '(user_id, (COALESCE(organization_id, 0)), role_id, (COALESCE(route_group, \'\')))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
