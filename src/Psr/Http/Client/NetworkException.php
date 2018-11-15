<?php

namespace Psr\Http\Client;

use Psr\Http\Message\RequestInterface;
use Throwable;

class NetworkException extends ClientException implements NetworkExceptionInterface
{
    /**
     * @var \Psr\Http\Message\RequestInterface
     */
    private $request;

    public function __construct(
        string $message,
        RequestInterface $request,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->request = $request;

        parent::__construct($message, $code, $previous);
    }

    /**
     * {@inheritDoc}
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
