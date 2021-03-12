<?php

namespace M6Web\Tornado\Adapter\ReactPhp;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    /** @var \React\EventLoop\LoopInterface */
    private $reactEventLoop;

    /** @var FailingPromiseCollection */
    private $unhandledFailingPromises;

    public function __construct(\React\EventLoop\LoopInterface $reactEventLoop)
    {
        $this->reactEventLoop = $reactEventLoop;
        $this->unhandledFailingPromises = new FailingPromiseCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $value = null;
        $isRejected = false;
        $promiseSettled = false;
        Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)->getReactPromise()->then(
            function ($result) use (&$value, &$promiseSettled) {
                $promiseSettled = true;
                $value = $result;
                $this->reactEventLoop->stop();
            },
            function ($result) use (&$value, &$isRejected, &$promiseSettled) {
                $promiseSettled = true;
                $value = $result;
                $isRejected = true;
                $this->reactEventLoop->stop();
            }
        );

        if (!$promiseSettled) {
            $this->reactEventLoop->run();
        }

        if (!$promiseSettled) {
            throw new \Error('Impossible to resolve the promise, no more task to execute.');
        }

        if ($isRejected) {
            /* @var $value \Exception */
            throw $value;
        }

        $this->unhandledFailingPromises->throwIfWatchedFailingPromiseExists();

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $fnWrapGenerator = function (\Generator $generator, \React\Promise\Deferred $deferred) use (&$fnWrapGenerator) {
            try {
                if (!$generator->valid()) {
                    $deferred->resolve($generator->getReturn());
                }
                $promise = $generator->current();
                if (!$promise instanceof Internal\PromiseWrapper) {
                    throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
                }
                Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)
                    ->getReactPromise()->then(
                        function ($result) use ($generator, $deferred, $fnWrapGenerator) {
                            try {
                                $generator->send($result);
                                $fnWrapGenerator($generator, $deferred);
                            } catch (\Throwable $throwable) {
                                $deferred->reject($throwable);
                            }
                        },
                        function ($reason) use ($generator, $deferred, $fnWrapGenerator) {
                            try {
                                $generator->throw($reason);
                                $fnWrapGenerator($generator, $deferred);
                            } catch (\Throwable $throwable) {
                                $deferred->reject($throwable);
                            }
                        }
                    );
            } catch (\Throwable $throwable) {
                $deferred->reject($throwable);
            }
        };

        $deferred = new \React\Promise\Deferred();
        $fnWrapGenerator($generator, $deferred);

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        return Internal\PromiseWrapper::createUnhandled(
            \React\Promise\all(
                array_map(
                    function (Promise $promise) {
                        return Internal\PromiseWrapper::toHandledPromise(
                            $promise,
                            $this->unhandledFailingPromises
                        )->getReactPromise();
                    },
                    $promises
                )
            ),
            $this->unhandledFailingPromises
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseForeach($traversable, callable $function): Promise
    {
        $promises = [];
        foreach ($traversable as $key => $value) {
            $promises[] = $this->async($function($value, $key));
        }

        return $this->promiseAll(...$promises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        return Internal\PromiseWrapper::createUnhandled(
            \React\Promise\race(
                array_map(
                    function (Promise $promise) {
                        return Internal\PromiseWrapper::toHandledPromise(
                            $promise,
                            $this->unhandledFailingPromises
                        )->getReactPromise();
                    },
                    $promises
                )
            ),
            $this->unhandledFailingPromises
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return Internal\PromiseWrapper::createHandled(new \React\Promise\FulfilledPromise($value));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        // Manually created promises are considered as handled.
        return Internal\PromiseWrapper::createHandled(new \React\Promise\RejectedPromise($throwable));
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->futureTick(function () use ($deferred) {
            $deferred->resolve(null);
        });

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->addTimer(
            $milliseconds / 1000 /* milliseconds per second */ ,
            function () use ($deferred) {
                $deferred->resolve(null);
            }
        );

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        return new Internal\Deferred(
            $deferred = new \React\Promise\Deferred(),
            // Manually created promises are considered as handled.
            Internal\PromiseWrapper::createHandled($deferred->promise())
        );
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->addReadStream(
            $stream,
            function ($stream) use ($deferred) {
                $this->reactEventLoop->removeReadStream($stream);
                $deferred->resolve($stream);
            }
        );

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->addWriteStream(
            $stream,
            function ($stream) use ($deferred) {
                $this->reactEventLoop->removeWriteStream($stream);
                $deferred->resolve($stream);
            }
        );

        return $deferred->getPromise();
    }
}
