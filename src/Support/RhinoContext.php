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
     * Stack of active overrides. Each entry: ['user' => ?Authenticatable, 'organization' => mixed].
     *
     * @var array<int, array{user: ?Authenticatable, organization: mixed}>
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

    public function hasOverride(): bool
    {
        return ! empty($this->stack);
    }

    /**
     * Push an explicit (user, organization) override onto the stack.
     */
    public function push(?Authenticatable $user, $organization): void
    {
        $this->stack[] = ['user' => $user, 'organization' => $organization];
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
    public function run(?Authenticatable $user, $organization, Closure $callback)
    {
        // Snapshot ambient state.
        $previousUser = $this->ambientUser();
        $hadOrganization = request()->attributes->has('organization');
        $previousOrganization = request()->attributes->get('organization');

        $this->push($user, $organization);

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
}
