<?php

namespace Rhino;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Rhino\Commands\ExportPostmanCommand;
use Rhino\Commands\ExportTypesCommand;
use Rhino\Commands\GenerateInvitationLink;
use Rhino\Commands\GenerateCommand;
use Rhino\Commands\BlueprintCommand;
use Rhino\Commands\InstallCommand;
use Rhino\Models\OrganizationInvitation;
use Rhino\Policies\InvitationPolicy;

class GlobalControllerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rhino.php', 'rhino');
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
        ]);
    }
}
