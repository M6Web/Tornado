<?php

namespace M6Web\Tornado\Adapter\Guzzle;

final class CurlMultiClientWrapper implements GuzzleClientWrapper
{
    /** @var \GuzzleHttp\Handler\CurlMultiHandler */
    private $curlMultiHandler;

    /** @var \GuzzleHttp\Client */
    private $guzzleClient;

    /**
     * CurlMultiClientWrapper constructor.
     *
     * @param array $clientConfig     configuration for \GuzzleHttp\Client, check corresponding documentation.
     *                                'handler' configuration will be ignored since built in this wrapper
     * @param array $curlMultiOptions options for \GuzzleHttp\Handler\CurlMultiHandler, check corresponding documentation.
     *                                Default value for 'select_timeout' is 0
     * @param array $middlewareStack  set of name => handler to push on the top of created \GuzzleHttp\HandlerStack,
     *                                check corresponding documentation
     */
    public function __construct(array $clientConfig = [], array $curlMultiOptions = [], array $middlewareStack = [])
    {
        $this->curlMultiHandler = new \GuzzleHttp\Handler\CurlMultiHandler(
            $curlMultiOptions + ['select_timeout' => 0]
        );

        $stack = \GuzzleHttp\HandlerStack::create($this->curlMultiHandler);
        foreach ($middlewareStack as $name => $middleware) {
            $stack->push($middleware, $name);
        }

        $this->guzzleClient = new \GuzzleHttp\Client(
            [
                'handler' => $stack,
            ] + $clientConfig
        );
    }

    public function getClient(): \GuzzleHttp\ClientInterface
    {
        return $this->guzzleClient;
    }

    public function tick(): void
    {
        $this->curlMultiHandler->tick();
    }
}
