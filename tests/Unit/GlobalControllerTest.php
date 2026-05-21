<?php

namespace Rhino\Tests\Unit;

use Rhino\Tests\TestCase;
use Rhino\Controllers\GlobalController;

class GlobalControllerTest extends TestCase
{
    public function test_global_controller_can_be_instantiated()
    {
        $controller = new GlobalController();
        $this->assertInstanceOf(GlobalController::class, $controller);
    }
}
