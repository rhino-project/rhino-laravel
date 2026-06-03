<?php

namespace Rhino\Exceptions;

use RuntimeException;

/**
 * Thrown by an AuthLifecycleHooks implementation to reject an auth action.
 *
 * When a token-issuing action (login/register) is rejected, the controller
 * revokes the just-issued token. For all actions the controller returns the
 * carried HTTP status (default 403) and message.
 */
class RhinoAuthRejected extends RuntimeException
{
    /**
     * The HTTP status code to return for the rejected action.
     */
    protected int $status;

    public function __construct(string $message = 'Authentication rejected', int $status = 403)
    {
        parent::__construct($message);

        $this->status = $status;
    }

    /**
     * Get the HTTP status code to return for the rejected action.
     */
    public function getStatus(): int
    {
        return $this->status;
    }
}
