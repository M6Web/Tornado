#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use M6Web\Tornado\Adapter;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\HttpClient;

function monitorRequest(EventLoop $eventLoop, HttpClient $httpClient, string $uri): Generator
{
    // Let's use Guzzle Psr7 implementation
    $request = new \GuzzleHttp\Psr7\Request('GET', $uri, [], null, '2.0');

    $start = microtime(true);
    /** @var \Psr\Http\Message\ResponseInterface */
    $response = yield $httpClient->sendRequest($request);
    $duration = microtime(true) - $start;

    return "[{$response->getStatusCode()}]\t $uri\t\t $duration";
}

// Choose your adapter.
$eventLoop = new Adapter\Tornado\EventLoop();
//$eventLoop = new Adapter\Tornado\SynchronousEventLoop();
//$eventLoop = new Adapter\Amp\EventLoop();
//$eventLoop = new Adapter\ReactPhp\EventLoop(new React\EventLoop\StreamSelectLoop());

// Choose your adapter
$httpClient = new Adapter\Symfony\HttpClient(new \Symfony\Component\HttpClient\CurlHttpClient(), $eventLoop, new \Http\Factory\Guzzle\ResponseFactory(), new \Http\Factory\Guzzle\StreamFactory());
//$httpClient = new Adapter\Guzzle\HttpClient($eventLoop, new Adapter\Guzzle\CurlMultiClientWrapper());

// Let's call several endpoints… concurrently!
echo "Let's start!\n";
echo "Requests in progress…\n";
$start = microtime(true);
$promises = [];
// You can download up to 379 parts.
// Check https://http2.akamai.com/demo for the full HTTP2 demonstration.
for ($i = 0; $i < 10; $i++) {
    $promises[] = $eventLoop->async(monitorRequest(
            $eventLoop,
            $httpClient,
        "https://http2.akamai.com/demo/tile-$i.png"
    ));
}

$results = $eventLoop->wait($eventLoop->promiseAll(...$promises));
$duration = microtime(true) - $start;

echo implode(PHP_EOL, $results).PHP_EOL;
echo "Global duration: $duration\n";
echo "Finished!\n";
