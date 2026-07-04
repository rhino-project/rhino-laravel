<?php

namespace Rhino\Tests\Unit;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Rhino\Support\RhinoContext;
use Rhino\Tests\TestCase;

class RhinoContextUser extends Authenticatable
{
    protected $guarded = [];
}

class RhinoContextTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \App\Models\User::class,
        ]);
    }

    protected function makeContext(): RhinoContext
    {
        return new RhinoContext();
    }

    // ----------------------------------------------------------------------
    // Fallback to ambient when no override.
    // ----------------------------------------------------------------------

    public function test_falls_back_to_ambient_when_no_override(): void
    {
        $ctx = $this->makeContext();

        $ambientUser = new RhinoContextUser(['id' => 7]);
        auth('sanctum')->setUser($ambientUser);
        $ambientOrg = (object) ['id' => 99];
        request()->attributes->set('organization', $ambientOrg);

        $this->assertFalse($ctx->hasOverride());
        $this->assertSame($ambientUser, $ctx->user());
        $this->assertSame($ambientOrg, $ctx->organization());
    }

    public function test_organization_is_null_when_no_override_and_no_request_attribute(): void
    {
        $ctx = $this->makeContext();
        request()->attributes->remove('organization');

        $this->assertNull($ctx->organization());
    }

    // ----------------------------------------------------------------------
    // Returns override when pushed.
    // ----------------------------------------------------------------------

    public function test_returns_override_when_pushed(): void
    {
        $ctx = $this->makeContext();

        $ambientUser = new RhinoContextUser(['id' => 1]);
        auth('sanctum')->setUser($ambientUser);
        request()->attributes->set('organization', (object) ['id' => 1]);

        $overrideUser = new RhinoContextUser(['id' => 2]);
        $overrideOrg = (object) ['id' => 2];

        $ctx->push($overrideUser, $overrideOrg);

        $this->assertTrue($ctx->hasOverride());
        $this->assertSame($overrideUser, $ctx->user());
        $this->assertSame($overrideOrg, $ctx->organization());

        // After pop, falls back to ambient again.
        $ctx->pop();
        $this->assertFalse($ctx->hasOverride());
        $this->assertSame($ambientUser, $ctx->user());
        $this->assertSame(1, $ctx->organization()->id);
    }

    public function test_nested_overrides_use_top_of_stack(): void
    {
        $ctx = $this->makeContext();

        $u1 = new RhinoContextUser(['id' => 10]);
        $u2 = new RhinoContextUser(['id' => 20]);

        $ctx->push($u1, (object) ['id' => 10]);
        $ctx->push($u2, (object) ['id' => 20]);

        $this->assertSame(20, $ctx->user()->id);
        $this->assertSame(20, $ctx->organization()->id);

        $ctx->pop();
        $this->assertSame(10, $ctx->user()->id);
        $this->assertSame(10, $ctx->organization()->id);

        $ctx->pop();
    }

    // ----------------------------------------------------------------------
    // run() pushes + restores.
    // ----------------------------------------------------------------------

    public function test_run_pushes_and_restores(): void
    {
        $ctx = $this->makeContext();

        $ambientUser = new RhinoContextUser(['id' => 1]);
        auth('sanctum')->setUser($ambientUser);
        $ambientOrg = (object) ['id' => 1];
        request()->attributes->set('organization', $ambientOrg);

        $overrideUser = new RhinoContextUser(['id' => 2]);
        $overrideOrg = (object) ['id' => 2];

        $seen = $ctx->run($overrideUser, $overrideOrg, function () use ($ctx, $overrideUser) {
            // Override visible via context AND ambient during the run.
            $this->assertTrue($ctx->hasOverride());
            $this->assertSame(2, $ctx->user()->id);
            $this->assertSame(2, $ctx->organization()->id);
            $this->assertSame($overrideUser, auth('sanctum')->user());
            $this->assertSame(2, request()->attributes->get('organization')->id);

            return 'callback-value';
        });

        // Return value propagated.
        $this->assertSame('callback-value', $seen);

        // Ambient restored, override popped.
        $this->assertFalse($ctx->hasOverride());
        $this->assertSame($ambientUser, auth('sanctum')->user());
        $this->assertSame(1, request()->attributes->get('organization')->id);
    }

    public function test_run_restores_even_on_exception(): void
    {
        $ctx = $this->makeContext();

        $ambientUser = new RhinoContextUser(['id' => 1]);
        auth('sanctum')->setUser($ambientUser);
        request()->attributes->remove('organization');

        try {
            $ctx->run(new RhinoContextUser(['id' => 2]), (object) ['id' => 2], function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('exception should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Restored: override gone, org attribute absent as before.
        $this->assertFalse($ctx->hasOverride());
        $this->assertSame($ambientUser, auth('sanctum')->user());
        $this->assertFalse(request()->attributes->has('organization'));
    }
}
