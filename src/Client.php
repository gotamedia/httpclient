<?php

declare(strict_types=1);

namespace Atoms\HttpClient;

use Atoms\Http\ResponseFactory;
use Atoms\Http\StreamFactory;
use Atoms\Http\UriFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
     * @var resource
     */
    private $curl;

    /**
     * Creates a new Client instance.
     */
    public function __construct()
    {
        /** Check that cURL is available */
        if (!function_exists('curl_init')) {
            throw new CurlNotFoundException(
                'To make HTTP requests, the PHP cURL extension must be available'
            );
        }

        $this->curl = curl_init();

        curl_setopt_array($this->curl, self::CURL_OPTIONS);
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->setOptionsFromRequest($request) === false) {
            throw new RequestException('Invalid request', $request);
        }

        if (($data = curl_exec($this->curl)) === false) {
            throw new RequestException(
                'Request error (' .  curl_errno($this->curl) . '): ' . curl_error($this->curl),
                $request
            );
        }

        return $this->createResponse($data);
    }

    /**
     * Creates a response from the request data.
     *
     * @param  string $data
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function createResponse($data): ResponseInterface
    {
        /** Get the header and body from the response data */
        $statusCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($data, 0, $headerSize);
        $body = substr($data, $headerSize);

        list($statusLine, $headers) = $this->parseHeaders(rtrim($rawHeaders));

        $streamFactory = new StreamFactory();
        $responseFactory = new ResponseFactory($streamFactory, new UriFactory());

        $response = $responseFactory->createResponseWithHeaders($statusCode, '', $headers)->withBody(
            $streamFactory->createStream($body)
        );

        return $response;
    }

    /**
     * Parses the raw response headers.
     *
     * @param  string $rawHeaders
     * @return array
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $rawHeaders = preg_split('|(\\r?\\n)|', $rawHeaders);
        $statusLine = array_shift($rawHeaders);

        $headers = [];
        foreach ($rawHeaders as $rawHeader) {
            list($name, $value) = preg_split('|: |', $rawHeader);

            $headers[$name] = $value;
        }

        return [$statusLine, $headers];
    }

    /**
     * Sets cURL options from the request.
     *
     * @param  \Psr\Http\Message\RequestInterface $request
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
