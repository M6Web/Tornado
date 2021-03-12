<?php

namespace M6Web\Tornado\Adapter\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class SynchronousEventLoop implements \M6Web\Tornado\EventLoop
{
    /** @var \Throwable[] */
    private $asyncThrowables = [];

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        // If there are some uncaught exceptions, throw the first one.
        if ($throwable = reset($this->asyncThrowables)) {
            throw $throwable;
        }

        assert($promise instanceof Internal\PendingPromise, 'Input promise was not created by this adapter.');
        $result = null;
        $promise->addCallbacks(
            function ($value) use (&$result) {
                $result = $value;
            },
            function (\Throwable $throwable) {
                throw $throwable;
            }
        );

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        try {
            while ($generator->valid()) {
                $promise = $generator->current();
                if (!$promise instanceof Internal\PendingPromise) {
                    throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
                }
                $promise->addCallbacks(
                    function ($value) use ($generator) {
                        $generator->send($value);
                    },
                    function (\Throwable $throwable) use ($generator) {
                        // Since this exception is caught, remove it from the list
                        $index = array_search($throwable, $this->asyncThrowables, true);
                        if ($index !== false) {
                            unset($this->asyncThrowables[$index]);
                        }
                        $generator->throw($throwable);
                    }
                );
            }

            return $this->promiseFulfilled($generator->getReturn());
        } catch (\Throwable $exception) {
            // Will have to check that this exception is caught later…
            $this->asyncThrowables[] = $exception;

            return $this->promiseRejected($exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        try {
            return $this->promiseFulfilled(array_map([$this, 'wait'], $promises));
        } catch (\Throwable $exception) {
            return $this->promiseRejected($exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function promiseForeach($traversable, callable $function): Promise
    {
        try {
            $results = [];
            foreach ($traversable as $key => $value) {
                $results[] = $this->wait($this->async($function($value, $key)));
            }

            return $this->promiseFulfilled($results);
        } catch (\Throwable $exception) {
            return $this->promiseRejected($exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        return reset($promises) ?: $this->promiseFulfilled(null);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        $promise = Internal\PendingPromise::createHandled();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        $promise = Internal\PendingPromise::createHandled();
        $promise->reject($throwable);

        return $promise;
    }

    /**
     * {@inheritdoc}
     *
     * Note: Because this loop is synchronous, this is an alias for fullfilledPromise.
     */
    public function idle(): Promise
    {
        return $this->promiseFulfilled(null);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        usleep($milliseconds * 1000 /* microseconds in 1 millisecond */);

        return $this->promiseFulfilled(null);
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        $deferred = new class() implements Deferred {
            /** @var SynchronousEventLoop */
            public $eventLoop;
            private $promise;

            public function getPromise(): Promise
            {
                if (!$this->promise) {
                    throw new \Error('Synchronous Deferred must be resolved/rejected before to retrieve its promise.');
                }

                return $this->promise;
            }

            public function resolve($value): void
            {
                $this->promise = $this->eventLoop->promiseFulfilled($value);
            }

            public function reject(\Throwable $throwable): void
            {
                $this->promise = $this->eventLoop->promiseRejected($throwable);
            }
        };
        $deferred->eventLoop = $this;

        return $deferred;
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        return $this->promiseFulfilled($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        return $this->promiseFulfilled($stream);
    }
}
