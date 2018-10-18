<?php

namespace Psr\Http\Client;

use Exception;

/**
 * Every HTTP client related exception MUST implement this interface.
 */
class ClientException extends Exception implements ClientExceptionInterface
{
}
