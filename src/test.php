<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Atoms\Http\RequestFactory;
use Atoms\Http\ResponseFactory;
use Atoms\Http\StreamFactory;
use Atoms\Http\UriFactory;
use Atoms\HttpClient\Client;

try {
    $requestFactory = new RequestFactory(new UriFactory(), new StreamFactory());
    $request = $requestFactory->createRequest('GET', 'https://api.dryg.net/dagar/v2.1/' . date('Y/m'));
    $response = (new Client(new ResponseFactory(new StreamFactory()), new StreamFactory()))->sendRequest($request);
} catch (Exception $e) {
    echo $e->getMessage();
}

echo (string)$response->getBody();
