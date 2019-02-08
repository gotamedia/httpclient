<?php

declare(strict_types=1);

namespace Atoms\HttpClient;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;

class ClientException extends Exception implements ClientExceptionInterface
{
}
