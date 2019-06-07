<?php

declare(strict_types=1);

namespace Atoms\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Client implements ClientInterface
{
    const CURL_OPTIONS = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYHOST => false, // Should be set to 2
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_TIMEOUT_MS => 5000
    ];

    /**
     * @var \Psr\Http\Message\ResponseFactoryInterface
     */
    private $responseFactory;

    /**
     * @var \Psr\Http\Message\StreamFactoryInterface
     */
    private $streamFactory;

    /**
     * @var resource
     */
    private $curl;

    /**
     * Creates a new Client instance.
     *
     * @param \Psr\Http\Message\ResponseFactoryInterface $responseFactory
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory
     * @param array $curlOptions
     * @throws \Atoms\HttpClient\CurlNotFoundException
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $curlOptions = []
    ) {
        /** Check that cURL is available */
        if (!function_exists('curl_init')) {
            throw new CurlNotFoundException(
                'To make HTTP requests, the PHP cURL extension must be available'
            );
        }

        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->curl = curl_init();

        curl_setopt_array($this->curl, array_replace(self::CURL_OPTIONS, $curlOptions));
    }

    /**
     * {@inheritDoc}
     *
     * @param \Psr\Http\Message\RequestInterface $request
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \Atoms\HttpClient\ClientException
     * @throws \Atoms\HttpClient\RequestException
     */
    public function sendRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        if ($this->setOptionsFromRequest($request) === false) {
            throw new RequestException('Invalid request', $request);
        }

        if (count($options) > 0) {
            if ($this->setOptions($options) === false) {
                throw new ClientException('Invalid options');
            }
        }

        $headers = [];
        $protocol = '';
        $reasonPhrase = '';

        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, function (
            $curl,
            $headerLine
        ) use (
            &$headers,
            &$protocol,
            &$reasonPhrase
        ) {
            $len = strlen($headerLine);
            $header = explode(':', $headerLine, 2);

            if (count($header) < 2) {
                $header = explode(' ', $headerLine, 3);

                if (count($header) === 3) {
                    $protocol = str_replace('HTTP/', '', $header[0]);
                    $reasonPhrase = $header[2];
                }

                return $len;
            }

            $name = trim($header[0]);

            if (array_key_exists($name, $headers)) {
                $headers[$name][] = trim($header[1]);

                return $len;
            }

            $headers[$name] = [trim($header[1])];

            return $len;
        });

        // @todo Check what kind of error occurred and throw appropriate exception.
        if (($data = curl_exec($this->curl)) === false) {
            throw new RequestException(
                'Request error (' .  curl_errno($this->curl) . '): ' . curl_error($this->curl),
                $request
            );
        }

        /** Get the body from the response data */
        $statusCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $body = substr($data, $headerSize);

        $response = $this->responseFactory->createResponse(
            $statusCode,
            $reasonPhrase
        );

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response
            ->withBody($this->streamFactory->createStream($body))
            ->withProtocolVersion($protocol);
    }

    /**
     * Sets an array of cURL options.
     *
     * @param array $options
     * @return bool
     */
    public function setOptions(array $options): bool
    {
        return curl_setopt_array($this->curl, $options);
    }

    /**
     * Sets cURL options from the request.
     *
     * @param \Psr\Http\Message\RequestInterface $request
     * @return bool
     */
    private function setOptionsFromRequest(RequestInterface $request): bool
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $array) {
            foreach ($array as $header) {
                $headers[] = "{$name}: {$header}";
            }
        }

        return curl_setopt_array(
            $this->curl,
            [
                CURLOPT_CUSTOMREQUEST => $request->getMethod(),
                CURLOPT_URL => (string)$request->getUri(),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => (string)$request->getBody(),
                CURLOPT_HTTP_VERSION => $request->getProtocolVersion() === '1.0'
                    ? CURL_HTTP_VERSION_1_0
                    : CURL_HTTP_VERSION_1_1
            ]
        );
    }
}
