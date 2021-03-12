#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Psr7;
use M6Web\Tornado;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a HTTP request a returns its body as a Json array.
 */
function getJsonResponseAsync(Tornado\HttpClient $httpClient, RequestInterface $request): Generator
{
    /** @var ResponseInterface $response */
    $response = yield $httpClient->sendRequest($request);

    return json_decode((string) $response->getBody(), true);
}

/**
 * Returns a Promise that will be resolved with a Json array.
 */
function requestJsonContent(Tornado\EventLoop $eventLoop, Tornado\HttpClient $httpClient): Tornado\Promise
{
    $request = new Psr7\Request(
        'GET',
        'http://httpbin.org/json',
        ['accept' => 'application/json']
    );

    return $eventLoop->async(getJsonResponseAsync($httpClient, $request));
}

function waitResponseSynchronously(Tornado\EventLoop $eventLoop, Tornado\HttpClient $httpClient)
{
    /** @var array $jsonArray */
    $jsonArray = $eventLoop->wait(requestJsonContent($eventLoop, $httpClient));
    echo '>>> '.json_encode($jsonArray).PHP_EOL;
}

function waitManyResponsesSynchronously(Tornado\EventLoop $eventLoop, Tornado\HttpClient $httpClient)
{
    $allJsonArrays = $eventLoop->wait(
        $eventLoop->promiseAll(
            requestJsonContent($eventLoop, $httpClient),
            requestJsonContent($eventLoop, $httpClient),
            requestJsonContent($eventLoop, $httpClient),
            requestJsonContent($eventLoop, $httpClient)
        )
    );

    foreach ($allJsonArrays as $index => $jsonArray) {
        echo "[$index]>>> ".json_encode($jsonArray).PHP_EOL;
    }
}

function promiseWaiter(Tornado\Promise $promise): Generator
{
    echo "I'm waiting a promise…\n";
    $result = yield $promise;
    echo "I received [$result]!\n";
}

function deferredResolver(Tornado\EventLoop $eventLoop, Tornado\Deferred $deferred): Generator
{
    yield $eventLoop->delay(1000);
    $deferred->resolve('Hello World!');
}

function waitDeferredSynchronously(Tornado\EventLoop $eventLoop)
{
    $deferred = $eventLoop->deferred();
    $eventLoop->wait($eventLoop->promiseAll(
        $eventLoop->async(deferredResolver($eventLoop, $deferred)),
        $eventLoop->async(promiseWaiter($deferred->getPromise()))
    ));
}

function failingAsynchronousFunction(Tornado\EventLoop $eventLoop): Generator
{
    yield $eventLoop->idle();

    throw new \Exception('This is an exception!');
}

function waitException(Tornado\EventLoop $eventLoop)
{
    try {
        $eventLoop->wait($eventLoop->async(failingAsynchronousFunction($eventLoop)));
    } catch (\Throwable $throwable) {
        echo $throwable->getMessage().PHP_EOL;
    }
}

// Choose your adapter.
$eventLoop = new Tornado\Adapter\Tornado\EventLoop();
//$eventLoop = new Tornado\Adapter\Tornado\SynchronousEventLoop();
//$eventLoop = new Tornado\Adapter\Amp\EventLoop();
//$eventLoop = new Tornado\Adapter\ReactPhp\EventLoop(new React\EventLoop\StreamSelectLoop());

// Tornado provides only one HttpClient implementation, using Guzzle
$httpClient = new Tornado\Adapter\Guzzle\HttpClient($eventLoop, new Tornado\Adapter\Guzzle\CurlMultiClientWrapper());

echo "Let's start!\n";
waitResponseSynchronously($eventLoop, $httpClient);
waitManyResponsesSynchronously($eventLoop, $httpClient);
waitDeferredSynchronously($eventLoop);
waitException($eventLoop);
echo "Finished!\n";
