<?php

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class Deferred implements \M6Web\Tornado\Deferred
{
    /** @var \React\Promise\Deferred */
    private $reactDeferred;

    /** @var PromiseWrapper */
    private $promise;

    public function __construct(\React\Promise\Deferred $reactDeferred, PromiseWrapper $promiseWrapper)
    {
        $this->reactDeferred = $reactDeferred;
        $this->promise = $promiseWrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    public function getPromiseWrapper(): PromiseWrapper
    {
        return $this->promise;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve($value): void
    {
        $this->reactDeferred->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(\Throwable $throwable): void
    {
        $this->reactDeferred->reject($throwable);
    }
}
