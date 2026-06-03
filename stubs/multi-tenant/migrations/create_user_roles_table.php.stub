<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            // organization_id is nullable: non-tenant groups (e.g. admin/driver)
            // have no organization. Tenant-group rows still require an org.
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            // route_group scopes a membership to a group. NULL = wildcard
            // (member of every group), the back-compat default.
            $table->string('route_group')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'organization_id', 'route_group']);
        });

        // NULL values are treated as distinct by SQLite/MySQL unique indexes, so
        // add an expression-based index that coalesces NULLs to a sentinel to keep
        // uniqueness holding for NULL-org / NULL-route_group rows on both drivers.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX user_roles_unique_coalesced ON user_roles '
                . '(user_id, role_id, COALESCE(organization_id, 0), COALESCE(route_group, \'\'))'
            );
        } elseif ($driver === 'mysql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX user_roles_unique_coalesced ON user_roles '
                . '(user_id, role_id, (COALESCE(organization_id, 0)), (COALESCE(route_group, \'\')))'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
