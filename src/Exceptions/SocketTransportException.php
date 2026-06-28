<?php

declare(strict_types=1);

namespace Smpp\Exceptions;

/**
 * Class SocketTransportException
 *
 * Part of the SmppException hierarchy so transport failures are caught by a
 * single catch (SmppException). SmppException itself extends RuntimeException,
 * so this still is-a RuntimeException.
 *
 * @package smpp\exceptions
 */
class SocketTransportException extends SmppException
{

}