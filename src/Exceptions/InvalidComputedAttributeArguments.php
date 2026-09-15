<?php

namespace Rhino\Exceptions;

use RuntimeException;

/**
 * Thrown when a client-requested computed attribute is given arguments that do
 * not match the model's declared parameter spec (unknown name, missing required
 * parameter, or a bare value sent to a multi-parameter attribute).
 *
 * Only ever raised AFTER the attribute name itself passed the model declaration
 * and the policy, so the message may name the attribute and its parameters
 * without leaking the existence of attributes the user may not see.
 */
class InvalidComputedAttributeArguments extends RuntimeException
{
}
