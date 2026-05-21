<?php

namespace Rhino\Tests\Feature;

use Rhino\Tests\TestCase;

class GlobalControllerWithoutMultiTenantTest extends TestCase
{
    public function test_global_controller_works_without_tenant_group()
    {
        $routeGroups = config('rhino.route_groups', []);
        $this->assertArrayNotHasKey('tenant', $routeGroups);
    }

    public function test_config_is_properly_set()
    {
        $config = config('rhino');
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('models', $config);
        $this->assertArrayHasKey('route_groups', $config);
        $this->assertArrayHasKey('multi_tenant', $config);
    }
}
