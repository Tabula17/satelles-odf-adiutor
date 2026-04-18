<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

class RedisRetryDispatcher
{
    private bool $running = false;

    public function __construct(
        private readonly RedisRetryScheduler $retryScheduler,
        private readonly int $intervalSeconds = 1,
        private readonly int $batchSize = 100,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        Coroutine::create(function (): void {
            while ($this->running) {
                $promoted = $this->retryScheduler->promoteDueJobs(null, $this->batchSize);

                if ($promoted > 0) {
                    $this->logger?->debug('[RedisRetryDispatcher] Promoted due retry jobs', [
                        'count' => $promoted,
                    ]);
                }

                Coroutine::sleep($this->intervalSeconds);
            }
        });
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
