<?php

declare(strict_types=1);

namespace Smpp\Exceptions;

use RuntimeException;

/**
 * Class SmppException
 *
 * Base class for every exception thrown by this library. Extends
 * RuntimeException so that all library exceptions keep runtime-exception
 * semantics while remaining catchable via a single SmppException base type.
 *
 * @package smpp\Exceptions
 */
class SmppException extends RuntimeException
{

}