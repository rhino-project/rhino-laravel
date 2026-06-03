<?php

namespace Rhino\Tests\Feature\GroupAuthHooks;

use Rhino\Contracts\AbstractAuthLifecycleHooks;
use Rhino\Exceptions\RhinoAuthRejected;

/**
 * Test hooks implementation used by GroupAuthTest. Records every event/context
 * it receives in a static log so tests can assert what fired, and optionally
 * rejects a configured event with a configured status.
 */
class TestAuthHooks extends AbstractAuthLifecycleHooks
{
    /** @var array<int, array{event: string, context: array}> */
    public static array $calls = [];

    /** @var array<string, int> event => status to reject with */
    public static array $reject = [];

    /** @var array<string, bool> event => throw a generic (non-reject) exception */
    public static array $throw = [];

    public static function reset(): void
    {
        static::$calls = [];
        static::$reject = [];
        static::$throw = [];
    }

    protected function record(string $event, $user, array $context): void
    {
        static::$calls[] = ['event' => $event, 'context' => $context];

        if (!empty(static::$throw[$event])) {
            throw new \RuntimeException("Boom in {$event}");
        }

        if (isset(static::$reject[$event])) {
            throw new RhinoAuthRejected("Rejected {$event}", static::$reject[$event]);
        }
    }

    public function afterLogin($user, array $context): void
    {
        $this->record('afterLogin', $user, $context);
    }

    public function afterLogout($user, array $context): void
    {
        $this->record('afterLogout', $user, $context);
    }

    public function afterRegister($user, array $context): void
    {
        $this->record('afterRegister', $user, $context);
    }

    public function afterPasswordRecover($user, array $context): void
    {
        $this->record('afterPasswordRecover', $user, $context);
    }

    public function afterPasswordReset($user, array $context): void
    {
        $this->record('afterPasswordReset', $user, $context);
    }
}
