#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use M6Web\Tornado\Adapter;
use M6Web\Tornado\EventLoop;

function throwingGenerator(): Generator
{
    throw new \Exception('Oops, this is an exception');
    yield;
}

// Choose your adapter.
$eventLoop = new Adapter\Tornado\EventLoop();
//$eventLoop = new Adapter\Tornado\SynchronousEventLoop();
//$eventLoop = new Adapter\Amp\EventLoop();
//$eventLoop = new Adapter\ReactPhp\EventLoop(new React\EventLoop\StreamSelectLoop());

echo "Let's start!\n";

// The waited promise may fail…
try {
    $eventLoop->wait(
        $eventLoop->async(
            (function (EventLoop $eventLoop) {
                // A rejected promise will throw an exception
                try {
                    yield $eventLoop->promiseRejected(
                        new \Exception('This is a rejected promise')
                    );
                } catch (\Exception $exception) {
                    echo "Exception caught: {$exception->getMessage()}\n";
                }

                // A throwing asynchronous function will reject its associated promise when you yield for it.
                $promise = $eventLoop->async(throwingGenerator());
                try {
                    yield $promise;
                } catch (\Exception $exception) {
                    echo "Exception caught: {$exception->getMessage()}\n";
                }

                // If your event loop is waiting a rejected promise, the exception will be propagated.
                throw new \Exception('This is the final exception');
            })($eventLoop)    // Do not forget to execute the anonymous function to obtain a generator
        )
    );
} catch (\Exception $exception) {
    echo "Exception caught: {$exception->getMessage()}\n";
}
echo "Finished!\n";
