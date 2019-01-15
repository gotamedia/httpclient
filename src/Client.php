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

        // @todo Check what kind of error occurred and throw appropriate exception.
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
     * @param string $data
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
        $reasonPhrase = implode(' ', array_slice(explode(' ', $statusLine), 2));
        $response = $this->responseFactory->createResponse($statusCode, $reasonPhrase);

        foreach ($headers as $key => $value) {
            // @todo The exploding of the value will cause certain dates to be split up, what to do?
            $response = $response->withHeader($key, explode(',', $value));
        }

        return $response->withBody($this->streamFactory->createStream($body));
    }

    /**
     * Parses the raw response headers.
     *
     * @param string $rawHeaders
     * @return array
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $rawHeaders = preg_split('|(\\r?\\n)|', $rawHeaders);
        $statusLine = array_shift($rawHeaders);

        $headers = [];
        foreach ($rawHeaders as $rawHeader) {
            list($name, $value) = preg_split('|:|', $rawHeader);

            $headers[$name] = $value;
        }

        return [$statusLine, $headers];
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
