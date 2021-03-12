#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use M6Web\Tornado\Adapter;
use M6Web\Tornado\EventLoop;

function asynchronousCountdown(EventLoop $eventLoop, string $name, int $count): Generator
{
    echo "[$name]\tLet me countdown from $count to 0.\n";
    for ($i = $count; $i >= 0; $i--) {
        echo "[$name]\t$i\n";

        // Let the event loop process other jobs before to continue.
        yield $eventLoop->idle();
    }
    echo "[$name] Bye!\n";

    return "[$name] Countdown $count";
}

// Choose your adapter.
$eventLoop = new Adapter\Tornado\EventLoop();
//$eventLoop = new Adapter\Tornado\SynchronousEventLoop();
//$eventLoop = new Adapter\Amp\EventLoop();
//$eventLoop = new Adapter\ReactPhp\EventLoop(new React\EventLoop\StreamSelectLoop());

echo "Let's start!\n";

// We can't get directly the result of an asynchronous function,
// but the event loop gives us a promise.
$promiseAlice10 = $eventLoop->async(asynchronousCountdown($eventLoop, 'Alice', 10));
$promiseBob4 = $eventLoop->async(asynchronousCountdown($eventLoop, 'Bob', 4));

// Run the event loop until our goal promise is reached.
$result = $eventLoop->wait(
    // We group both promises in one, to run them concurrently.
    $eventLoop->promiseAll($promiseAlice10, $promiseBob4)
);

var_dump($result);
echo "Finished!\n";
