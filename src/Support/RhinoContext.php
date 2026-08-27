<?php

namespace Rhino\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Holds an optional stack of explicit (user, organization) overrides so that
 * Rhino resource queries can resolve tenant context deterministically both
 * inside a tenant HTTP request (ambient) and outside one (jobs, commands,
 * tests) via explicit overrides.
 */
class RhinoContext
{
    /**
     * Stack of active overrides. Each entry:
     * ['user' => ?Authenticatable, 'organization' => mixed, 'routeGroup' => ?string].
     *
     * @var array<int, array{user: ?Authenticatable, organization: mixed, routeGroup: ?string}>
     */
    protected array $stack = [];

    /**
     * Current user: the top override's user when an override is active,
     * otherwise the ambient sanctum user.
     */
    public function user(): ?Authenticatable
    {
        if ($this->hasOverride()) {
            return $this->stack[array_key_last($this->stack)]['user'];
        }

        return $this->ambientUser();
    }

    /**
     * Current organization: the top override's org when an override is active,
     * otherwise the ambient request organization.
     */
    public function organization()
    {
        if ($this->hasOverride()) {
            return $this->stack[array_key_last($this->stack)]['organization'];
        }

        return $this->ambientOrganization();
    }

    /**
     * Current route group: the innermost override that names one, otherwise the
     * ambient group of the matched route.
     *
     * Unlike user/organization, a null in an override does NOT mask the ambient
     * value — an explicit context can only ADD a group, never erase the one the
     * request is already being served by. So Rhino::forUser($u)->query(...)
     * inside a non-tenant request keeps that request's group.
     */
    public function routeGroup(): ?string
    {
        for ($i = array_key_last($this->stack); $i !== null && $i >= 0; $i--) {
            $group = $this->stack[$i]['routeGroup'] ?? null;

            if (is_string($group) && $group !== '') {
                return $group;
            }
        }

        return $this->ambientRouteGroup();
    }

    public function hasOverride(): bool
    {
        return ! empty($this->stack);
    }

    /**
     * Push an explicit (user, organization, route group) override onto the stack.
     */
    public function push(?Authenticatable $user, $organization, ?string $routeGroup = null): void
    {
        $this->stack[] = [
            'user' => $user,
            'organization' => $organization,
            'routeGroup' => $routeGroup,
        ];
    }

    /**
     * Pop the top override off the stack.
     */
    public function pop(): void
    {
        array_pop($this->stack);
    }

    /**
     * Run $callback with an explicit (user, organization) context, safely
     * isolated: the previous ambient auth user and request org attribute are
     * snapshotted and restored afterwards (even on exception), and the override
     * is popped. Ambient is also set during the run so the app's user-aware
     * global scopes resolve correctly.
     *
     * @return mixed The callback's return value.
     */
    public function run(?Authenticatable $user, $organization, Closure $callback, ?string $routeGroup = null)
    {
        // Snapshot ambient state.
        $previousUser = $this->ambientUser();
        $hadOrganization = request()->attributes->has('organization');
        $previousOrganization = request()->attributes->get('organization');

        $this->push($user, $organization, $routeGroup);

        if ($user) {
            auth('sanctum')->setUser($user);
        }
        if ($organization) {
            request()->attributes->set('organization', $organization);
        }

        try {
            return $callback();
        } finally {
            // Restore ambient auth user. setUser() rejects null, so forget the
            // user explicitly when there was none before the run.
            $guard = auth('sanctum');
            if ($previousUser) {
                $guard->setUser($previousUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }

            // Restore ambient organization attribute exactly as it was.
            if ($hadOrganization) {
                request()->attributes->set('organization', $previousOrganization);
            } else {
                request()->attributes->remove('organization');
            }

            $this->pop();
        }
    }

    /**
     * Resolve the ambient authenticated user from the sanctum guard, tolerating
     * environments where the guard is not configured.
     */
    protected function ambientUser(): ?Authenticatable
    {
        try {
            return auth('sanctum')->user();
        } catch (\Throwable $e) {
            return auth()->user();
        }
    }

    /**
     * Resolve the ambient organization from the current request attributes.
     */
    protected function ambientOrganization()
    {
        return request()->attributes->get('organization');
    }

    /**
     * Resolve the ambient route group from the matched route's 'route_group'
     * default — the same source EnforceGroupMembership, ResourcePolicy and
     * AuthController use. Null outside a request, when no route matched, or
     * when the route carries no group tag.
     */
    protected function ambientRouteGroup(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $group = request()->route()?->defaults['route_group'] ?? null;

        return is_string($group) && $group !== '' ? $group : null;
    }
}
