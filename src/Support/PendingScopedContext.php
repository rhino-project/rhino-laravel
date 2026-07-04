<?php

namespace Rhino\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fluent explicit-context builder returned by Rhino::forUser(). Lets callers
 * scope resource queries by an explicit (user, organization) outside a tenant
 * HTTP request (jobs, commands, tests) — the org scope is applied from the
 * passed organization, NOT from the route.
 */
class PendingScopedContext
{
    protected ?Authenticatable $user;

    protected $organization;

    public function __construct(?Authenticatable $user, $organization = null)
    {
        $this->user = $user;
        $this->organization = $organization;
    }

    /**
     * Set the explicit organization for this context.
     */
    public function inOrganization($organization): self
    {
        $this->organization = $organization;

        return $this;
    }

    /**
     * Build a tenant-scoped query for $modelClass using this explicit context.
     *
     * The organization is applied deterministically at build time, so the
     * override and request org are restored right after — a later ambient
     * Rhino::query() in the same (long-lived) process then fails closed instead
     * of inheriting this org. The auth user is left set because the app's
     * user-aware auto-scopes read it when the (deferred) query executes; prefer
     * run() for full isolation in queue workers.
     */
    public function query(string $modelClass): Builder
    {
        return $this->buildScoped(fn () => app(ResourceScope::class)->query($modelClass));
    }

    /**
     * Like query(), but also applies a whitelisted named scope.
     */
    public function scopedQuery(string $modelClass, ?string $scope = null): Builder
    {
        return $this->buildScoped(fn () => app(ResourceScope::class)->scopedQuery($modelClass, $scope));
    }

    /**
     * Run $callback with this explicit context, isolated and restored afterward
     * (preferred for jobs/queue workers).
     *
     * @return mixed
     */
    public function run(Closure $callback)
    {
        return app(RhinoContext::class)->run($this->user, $this->organization, $callback);
    }

    /**
     * Activate this explicit context only for the duration of the build, then
     * pop the override and restore the request org so it cannot leak into a
     * later ambient query. The auth user stays set for the deferred auto-scope
     * read at execution time.
     */
    protected function buildScoped(Closure $build): Builder
    {
        $context = app(RhinoContext::class);
        $request = request();
        $hadOrg = $request->attributes->has('organization');
        $previousOrg = $request->attributes->get('organization');

        $context->push($this->user, $this->organization);

        if ($this->user) {
            auth('sanctum')->setUser($this->user);
        }
        if ($this->organization) {
            $request->attributes->set('organization', $this->organization);
        }

        try {
            return $build();
        } finally {
            $context->pop();

            if ($hadOrg) {
                $request->attributes->set('organization', $previousOrg);
            } else {
                $request->attributes->remove('organization');
            }
        }
    }
}
