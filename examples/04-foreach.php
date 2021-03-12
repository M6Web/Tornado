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
        yield $eventLoop->delay(100);
    }
    echo "[$name] Bye!\n";

    return "[$name] Countdown $count";
}

function compareMethods(EventLoop $eventLoop)
{
    $dataSet = [
        'Alice  ' => 10,
        'Bob    ' => 5,
        'Charlie' => 3,
        'David  ' => 7,
    ];

    // Naive approach with a classic foreach.
    // Unfortunately, each promise will block the others to be processed…
    $start = microtime(true);
    echo "=== Native foreach ===\n";
    $result = [];
    foreach ($dataSet as $name => $count) {
        $result[] = yield $eventLoop->async(asynchronousCountdown($eventLoop, $name, $count));
    }
    var_dump($result);
    $duration = (microtime(true) - $start);
    echo "Duration (seconds): $duration\n\n";

    // The good approach, but a little verbose.
    echo "=== promiseAll ===\n";
    $start = microtime(true);
    $allPromises = [];
    foreach ($dataSet as $name => $count) {
        $allPromises[] = $eventLoop->async(asynchronousCountdown($eventLoop, $name, $count));
    }
    var_dump(yield $eventLoop->promiseAll(...$allPromises));
    $duration = (microtime(true) - $start);
    echo "Duration (seconds): $duration\n\n";

    // Using promiseForeach
    echo "=== promiseForeach ===\n";
    $start = microtime(true);
    $result = yield $eventLoop->promiseForeach($dataSet, function ($count, $name) use ($eventLoop) {
        return yield $eventLoop->async(asynchronousCountdown($eventLoop, $name, $count));
    });
    var_dump($result);
    $duration = (microtime(true) - $start);
    echo "Duration (seconds): $duration\n";
}

// Choose your adapter.
$eventLoop = new Adapter\Tornado\EventLoop();
//$eventLoop = new Adapter\Tornado\SynchronousEventLoop();
//$eventLoop = new Adapter\Amp\EventLoop();
//$eventLoop = new Adapter\ReactPhp\EventLoop(new React\EventLoop\StreamSelectLoop());

echo "Let's start!\n";
// Run the event loop until our goal promise is reached.
$result = $eventLoop->wait(
    $eventLoop->async(compareMethods($eventLoop))
);

var_dump($result);
echo "Finished!\n";
