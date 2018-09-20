<?php

namespace M6Web\Tornado\Adapter\Tornado\Internal;

use M6Web\Tornado\EventLoop;

/**
 * @internal
 */
class StreamEventLoop
{
    /**
     * @var resource[]
     */
    private $readStreams = [];
    /**
     * @var resource[]
     */
    private $writeStreams = [];
    /**
     * @var PendingPromise[]
     */
    private $pendingPromises = [];

    public function readable(EventLoop $eventLoop, $stream): PendingPromise
    {
        return $this->recordStream($eventLoop, $stream, $this->readStreams);
    }

    public function writable(EventLoop $eventLoop, $stream): PendingPromise
    {
        return $this->recordStream($eventLoop, $stream, $this->writeStreams);
    }

    private function internalLoop(EventLoop $eventLoop): \Generator
    {
        $except = null;
        while ($this->readStreams || $this->writeStreams) {
            yield $eventLoop->idle();

            $read = $this->readStreams;
            $write = $this->writeStreams;
            stream_select($read, $write, $except, 0);

            foreach ($read as $stream) {
                $streamId = (int) $stream;
                $pendingPromise = $this->pendingPromises[$streamId];
                unset($this->readStreams[$streamId]);
                unset($this->pendingPromises[$streamId]);
                $pendingPromise->resolve($stream);
            }

            foreach ($write as $stream) {
                $streamId = (int) $stream;
                $pendingPromise = $this->pendingPromises[$streamId];
                unset($this->writeStreams[$streamId]);
                unset($this->pendingPromises[$streamId]);
                $pendingPromise->resolve($stream);
            }
        }
    }

    private function recordStream(EventLoop $eventLoop, $stream, array &$streamsList): PendingPromise
    {
        $streamId = (int) $stream;
        if (isset($this->pendingPromises[$streamId])) {
            return $this->pendingPromises[$streamId];
        }
        $this->pendingPromises[$streamId] = ($pendingPromise = new PendingPromise());
        $streamsList[$streamId] = $stream;

        if (count($this->pendingPromises) === 1) {
            $eventLoop->async($this->internalLoop($eventLoop));
        }

        return $pendingPromise;
    }
}