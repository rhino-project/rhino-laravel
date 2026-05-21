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
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('email')->index();
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique()->index();
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'accepted', 'expired', 'cancelled'])->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Prevent duplicate pending invitations for same email + organization
            $table->unique(['email', 'organization_id', 'status'], 'org_inv_unique_pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
