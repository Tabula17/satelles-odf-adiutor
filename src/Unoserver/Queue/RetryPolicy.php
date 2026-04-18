<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

final readonly class RetryPolicy
{
    public function __construct(
        public int   $baseDelaySeconds = 2,
        public int   $maxDelaySeconds = 60,
        public float $jitterRatio = 0.10
    ) {
    }

    public function calculateDelaySeconds(int $attempts): int
    {
        $attempts = max(1, $attempts);

        $delay = $this->baseDelaySeconds * (2 ** ($attempts - 1));
        $delay = min($delay, $this->maxDelaySeconds);

        if ($this->jitterRatio > 0) {
            $jitter = (int) round($delay * $this->jitterRatio);
            $variance = random_int(-$jitter, $jitter);
            $delay = max(1, $delay + $variance);
        }

        return $delay;
    }

    public function calculateNextRetryAt(int $attempts, ?int $fromTimestamp = null): int
    {
        $fromTimestamp ??= time();

        return $fromTimestamp + $this->calculateDelaySeconds($attempts);
    }
}
