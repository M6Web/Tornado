<?php

namespace M6Web\Tornado\Adapter\Guzzle;

use GuzzleHttp\Exception\RequestException;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpClient implements \M6Web\Tornado\HttpClient
{
    /** @var EventLoop */
    private $eventLoop;

    /** @var GuzzleClientWrapper */
    private $clientWrapper;

    private $nbConcurrentRequests = 0;

    public function __construct(EventLoop $eventLoop, GuzzleClientWrapper $clientWrapper)
    {
        $this->eventLoop = $eventLoop;
        $this->clientWrapper = $clientWrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): Promise
    {
        $request = $this->http2fallback($request);
        $deferred = $this->eventLoop->deferred();

        $this->clientWrapper->getClient()->sendAsync($request)->then(
            function (ResponseInterface $response) use ($deferred) {
                $deferred->resolve($response);
                $this->nbConcurrentRequests--;
            },
            function (\Exception $exception) use ($deferred) {
                // Guzzle may throw an exception with a valid response.
                // We handle them as a success.
                if ($exception instanceof RequestException && $exception->getResponse()) {
                    $deferred->resolve($exception->getResponse());
                } else {
                    $deferred->reject($exception);
                }
                $this->nbConcurrentRequests--;
            }
        );

        if ($this->nbConcurrentRequests++ === 0) {
            $this->eventLoop->async($this->guzzleEventLoop());
        }

        return $deferred->getPromise();
    }

    private function http2fallback(RequestInterface $request): RequestInterface
    {
        if ($request->getProtocolVersion() !== '2.0') {
            return $request;
        }

        // Check that HTTP/2 is effectively supported by the system, and fallback to HTTP/1.1 if needed.
        // Inspired from https://github.com/symfony/http-client/blob/master/CurlHttpClient.php
        if (
            'https' !== $request->getUri()->getScheme()
            || !\defined('CURL_VERSION_HTTP2')
            || !(CURL_VERSION_HTTP2 & curl_version()['features'])
        ) {
            return $request->withProtocolVersion('1.1');
        }

        return $request;
    }

    private function guzzleEventLoop(): \Generator
    {
        do {
            yield $this->eventLoop->idle();
            $this->clientWrapper->tick();
        } while ($this->nbConcurrentRequests);
    }
}
