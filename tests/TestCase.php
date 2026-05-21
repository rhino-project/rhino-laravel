<?php

namespace Rhino\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rhino\GlobalControllerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Load test models directly
        if (file_exists(__DIR__ . '/Models/Organization.php')) {
            require_once __DIR__ . '/Models/Organization.php';
        }
        if (file_exists(__DIR__ . '/Models/User.php')) {
            require_once __DIR__ . '/Models/User.php';
        }
        if (file_exists(__DIR__ . '/Models/UserRole.php')) {
            require_once __DIR__ . '/Models/UserRole.php';
        }
        if (file_exists(__DIR__ . '/Models/Role.php')) {
            require_once __DIR__ . '/Models/Role.php';
        }
        
        // Also register in autoloader
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            $composer = require $autoloadPath;
            if ($composer instanceof \Composer\Autoload\ClassLoader) {
                $composer->addPsr4('App\\Models\\', __DIR__ . '/Models/');
            }
        }
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app)
    {
        return [
            GlobalControllerServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup rhino config
        $app['config']->set('rhino', [
            'models' => [],
            'route_groups' => [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ],
            'multi_tenant' => [
                'organization_identifier_column' => 'id',
            ],
        ]);

        // Register test models for autoloading
        // Use the root autoloader from the package directory
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath) && file_exists(__DIR__ . '/Models')) {
            $composer = require $autoloadPath;
            if ($composer instanceof \Composer\Autoload\ClassLoader) {
                $composer->addPsr4('App\\Models\\', __DIR__ . '/Models/');
            }
        }
        
        // Also register in Laravel's app namespace
        $app->bind('path', function () {
            return __DIR__ . '/../../';
        });
    }
}
