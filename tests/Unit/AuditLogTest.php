<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Models\AuditLog;
use Rhino\Tests\TestCase;

class AuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('action');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function test_casts_old_and_new_values_to_arrays(): void
    {
        $log = AuditLog::create([
            'auditable_type' => 'App\\Models\\Post',
            'auditable_id' => 1,
            'action' => 'updated',
            'old_values' => ['title' => 'a'],
            'new_values' => ['title' => 'b'],
        ]);

        $fresh = $log->fresh();
        $this->assertIsArray($fresh->old_values);
        $this->assertSame(['title' => 'a'], $fresh->old_values);
        $this->assertSame(['title' => 'b'], $fresh->new_values);
    }

    public function test_uses_the_audit_logs_table(): void
    {
        $this->assertSame('audit_logs', (new AuditLog())->getTable());
    }

    public function test_auditable_is_a_morph_to_relationship(): void
    {
        $this->assertInstanceOf(MorphTo::class, (new AuditLog())->auditable());
    }

    public function test_user_is_a_belongs_to_relationship(): void
    {
        $this->assertInstanceOf(BelongsTo::class, (new AuditLog())->user());
    }
}
