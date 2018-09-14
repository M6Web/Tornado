<?php

namespace M6Web\Tornado\Adapter\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    /**
     * @var Internal\StreamEventLoop
     */
    private $streamLoop;
    private $tasks = [];

    public function __construct()
    {
        $this->streamLoop = new Internal\StreamEventLoop();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $promiseIsPending = true;
        $finalAction = function () {throw new \LogicException('Cannot resolve waited promise.'); };
        $this->toPendingPromise($promise)->addCallbacks(
            function ($value) use (&$finalAction) {
                $finalAction = function () use ($value) {return $value; };
            },
            function (\Throwable $throwable) use (&$finalAction) {
                $finalAction = function () use ($throwable) {throw $throwable; };
            }
        );

        do {
            // Copy tasks list to safely allow tasks addition by tasks themselves
            $allTasks = $this->tasks;
            $this->tasks = [];
            foreach ($allTasks as $task) {
                try {
                    if (!$task->generator->valid()) {
                        $task->promise->resolve($task->generator->getReturn());
                        // This task is finished
                        continue;
                    }

                    $blockingPromise = $this->toPendingPromise($task->generator->current());
                    $blockingPromise->addCallbacks(
                        function ($value) use ($task) {
                            try {
                                $task->generator->send($value);
                                $this->tasks[] = $task;
                            } catch (\Throwable $exception) {
                                $task->promise->reject($exception);
                            }
                        },
                        function (\Throwable $throwable) use ($task) {
                            try {
                                $task->generator->throw($throwable);
                                $this->tasks[] = $task;
                            } catch (\Throwable $exception) {
                                $task->promise->reject($exception);
                            }
                        }
                    );
                } catch (\Throwable $exception) {
                    $task->promise->reject($exception);
                }
            }
        } while ($promiseIsPending && $this->tasks);

        return $finalAction();
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $task = new class() {
            public $generator;
            public $promise;
        };
        $task->generator = $generator;
        $task->promise = new Internal\PendingPromise();
        $this->tasks[] = $task;

        return $this->fromPendingPromise($task->promise);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $nbPromises = count($promises);
        if ($nbPromises === 0) {
            return $this->promiseFulfilled([]);
        }

        $globalPromise = new Internal\PendingPromise();
        $allResults = [];

        // To ensure that the last resolved promise resolves the global promise immediately
        $waitOnePromise = function (int $index, Promise $promise) use ($globalPromise, &$nbPromises, &$allResults): \Generator {
            try {
                $allResults[$index] = yield $promise;
            } catch (\Throwable $throwable) {
                // Prevent to reject the globalPromise twice
                if ($nbPromises > 0) {
                    $nbPromises = -1;
                    $globalPromise->reject($throwable);

                    return;
                }
            }
            // Last resolved promise resolved globalPromise
            if ($nbPromises === count($allResults)) {
                $globalPromise->resolve($allResults);
            }
        };

        foreach ($promises as $index => $promise) {
            $this->async($waitOnePromise($index, $promise));
        }

        return $this->fromPendingPromise($globalPromise);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        if (empty($promises)) {
            return $this->promiseFulfilled(null);
        }

        $globalPromise = new Internal\PendingPromise();
        $isFirstPromise = true;

        $wrapPromise = function (Promise $promise) use ($globalPromise, &$isFirstPromise): \Generator {
            try {
                $result = yield $promise;
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $globalPromise->resolve($result);
                }
            } catch (\Throwable $throwable) {
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $globalPromise->reject($throwable);
                }
            }
        };

        foreach ($promises as $index => $promise) {
            $this->async($wrapPromise($promise));
        }

        return $this->fromPendingPromise($globalPromise);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return $this->fromPendingPromise((new Internal\PendingPromise())->resolve($value));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return $this->fromPendingPromise((new Internal\PendingPromise())->reject($throwable));
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        // Add an asynchronous function that resolve immediately
        return $this->async((function (): \Generator {
            return;
            yield;
        })());
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        $deferred = new class() implements Deferred {
            /**
             * @var Internal\PendingPromise
             */
            public $internalPromise;
            /**
             * @var Promise
             */
            public $publicPromise;

            public function getPromise(): Promise
            {
                return $this->publicPromise;
            }

            public function resolve($value)
            {
                $this->internalPromise->resolve($value);
            }

            public function reject(\Throwable $throwable)
            {
                $this->internalPromise->reject($throwable);
            }
        };
        $deferred->internalPromise = new Internal\PendingPromise();
        $deferred->publicPromise = $this->fromPendingPromise($deferred->internalPromise);

        return $deferred;
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        return $this->fromPendingPromise(
            $this->streamLoop->readable($this, $stream)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        return $this->fromPendingPromise(
            $this->streamLoop->writable($this, $stream)
        );
    }

    private function fromPendingPromise(Internal\PendingPromise $pendingPromise): Promise
    {
        $promise = new class() implements Promise {
            public $pendingPromise;
        };
        $promise->pendingPromise = $pendingPromise;

        return $promise;
    }

    private function toPendingPromise(Promise $promise): Internal\PendingPromise
    {
        return $promise->pendingPromise;
    }
}
