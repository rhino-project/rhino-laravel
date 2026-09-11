<?php

namespace Rhino\Exceptions;

use RuntimeException;

/**
 * Thrown when a client-requested named scope is given arguments that do not
 * match the model's declared parameter spec (unknown name, missing required
 * parameter, or a bare value sent to a multi-parameter scope).
 *
 * Only ever raised AFTER the scope name itself passed the model whitelist and
 * the policy, so the message may name the scope and its parameters without
 * leaking the existence of scopes the user may not use.
 */
class InvalidScopeArguments extends RuntimeException
{
}
