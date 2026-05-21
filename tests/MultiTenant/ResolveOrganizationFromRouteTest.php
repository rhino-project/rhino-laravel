<?php

namespace Rhino\Tests\MultiTenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Rhino\Http\Middleware\ResolveOrganizationFromRoute;
use Rhino\Tests\TestCase;

class ResolveOrganizationFromRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rhino.multi_tenant.organization_identifier_column' => 'slug']);

        // Create organizations table
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function test_middleware_passes_through_when_no_organization_parameter()
    {
        $middleware = new ResolveOrganizationFromRoute();
        $request = Request::create('/api/users', 'GET');
        
        $response = $middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['success' => true], json_decode($response->getContent(), true));
    }

    public function test_middleware_returns_404_when_organization_not_found()
    {
        $middleware = new ResolveOrganizationFromRoute();
        $request = Request::create('/api/nonexistent-org/users', 'GET');
        
        // Create a mock route with organization parameter using a mock
        $route = $this->createMock(\Illuminate\Routing\Route::class);
        $route->method('hasParameter')->with('organization')->willReturn(true);
        $route->method('parameter')->with('organization')->willReturn('nonexistent-org');
        
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $response = $middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Organization not found', $data['message'] ?? null);
    }

    public function test_middleware_resolves_organization_by_slug()
    {
        // Create organization using model
        $organization = \App\Models\Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        $middleware = new ResolveOrganizationFromRoute();
        $request = Request::create('/api/test-org/users', 'GET');
        
        // Create a mock route with organization parameter
        $route = $this->createMock(\Illuminate\Routing\Route::class);
        $route->method('hasParameter')->with('organization')->willReturn(true);
        $route->method('parameter')->with('organization')->willReturn('test-org');
        
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $response = $middleware->handle($request, function ($req) {
            $org = $req->attributes->get('organization');
            return response()->json([
                'success' => true,
                'organization_id' => $org->id,
                'organization_slug' => $org->slug,
            ]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals($organization->id, $data['organization_id']);
        $this->assertEquals('test-org', $data['organization_slug']);
    }
}
