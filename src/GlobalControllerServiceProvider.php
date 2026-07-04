<?php

namespace Rhino;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Rhino\Support\ResourceScope;
use Rhino\Support\RhinoContext;
use Rhino\Support\RhinoManager;
use Rhino\Commands\ExportPostmanCommand;
use Rhino\Commands\ExportTypesCommand;
use Rhino\Commands\GenerateInvitationLink;
use Rhino\Commands\GenerateCommand;
use Rhino\Commands\BlueprintCommand;
use Rhino\Commands\InstallCommand;
use Rhino\Commands\MigratePermissionsCommand;
use Rhino\Models\OrganizationInvitation;
use Rhino\Policies\InvitationPolicy;

class GlobalControllerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rhino.php', 'rhino');

        // Rhino resource-scope resolver services.
        $this->app->singleton(RhinoContext::class);
        $this->app->singleton(ResourceScope::class);
        $this->app->singleton(RhinoManager::class);

        // Register the Rhino facade alias so `Rhino::query(...)` works.
        if (class_exists(AliasLoader::class)) {
            AliasLoader::getInstance()->alias('Rhino', \Rhino\Facades\Rhino::class);
        }
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../routes/api.php' => base_path('routes/api.php'),
        ], 'routes');

        $this->publishes([
            __DIR__.'/../config/rhino.php' => config_path('rhino.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../stubs/RhinoModel.php.stub' => base_path('stubs/RhinoModel.php.stub'),
        ], 'rhino-stubs');

        // Register invitation policy
        Gate::policy(OrganizationInvitation::class, InvitationPolicy::class);

        $this->commands([
            InstallCommand::class,
            GenerateCommand::class,
            BlueprintCommand::class,
            GenerateInvitationLink::class,
            ExportPostmanCommand::class,
            ExportTypesCommand::class,
            MigratePermissionsCommand::class,
        ]);
    }
}
