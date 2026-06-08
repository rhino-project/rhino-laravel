<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            // The shared permission set a role has within this organization.
            $table->json('permissions')->nullable();
            $table->timestamps();

            // One row per (organization, role).
            $table->unique(['organization_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_role_permissions');
    }
};
