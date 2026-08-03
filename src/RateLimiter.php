<?php

namespace TypechoPlugin\PandaBangumi;

final class RateLimiter
{
    private const STATE_LOCK_WAIT_MILLISECONDS = 50;

    public function __construct(private CacheStore $cacheStore)
    {
    }

    public function consumeApi(): void
    {
        $this->consume('api', 20, 1.0);
    }

    public function consumeCover(): void
    {
        $this->consume('cover', 32, 2.0);
    }

    public function consume(string $bucket, int $capacity, float $refillPerSecond): void
    {
        $capacity = max(1, $capacity);
        $refillPerSecond = max(0.001, $refillPerSecond);
        $lockHandle = $this->cacheStore->acquireShardLockWithWait(
            'rate-limit',
            'global',
            self::STATE_LOCK_WAIT_MILLISECONDS
        );
        if ($lockHandle === false) {
            throw new RateLimitExceeded(1);
        }

        try {
            $statePath = $this->cacheStore->statePath('rate-limit.php');
            $stateExists = is_file($statePath);
            $state = $this->cacheStore->read($statePath);
            if ($stateExists && (
                (int)($state['version'] ?? 0) !== 1
                || !isset($state['buckets'])
                || !is_array($state['buckets'])
            )) {
                throw new RateLimitExceeded(1);
            }

            if (!$stateExists) {
                $state = array('version' => 1, 'buckets' => array());
            }

            $now = $this->cacheStore->now();
            $hasStoredBucket = array_key_exists($bucket, $state['buckets']);
            $stored = $state['buckets'][$bucket] ?? array();
            if ($hasStoredBucket && (
                !is_array($stored)
                || !isset($stored['tokens'], $stored['updated_at'])
                || !is_numeric($stored['tokens'])
                || !is_numeric($stored['updated_at'])
            )) {
                throw new RateLimitExceeded(1);
            }
            $updatedAt = min($now, (int)($stored['updated_at'] ?? $now));
            $tokens = isset($stored['tokens']) && is_numeric($stored['tokens'])
                ? (float)$stored['tokens']
                : (float)$capacity;
            $tokens = min((float)$capacity, max(0.0, $tokens + (($now - $updatedAt) * $refillPerSecond)));

            if ($tokens < 1.0) {
                $state['buckets'][$bucket] = array('tokens' => $tokens, 'updated_at' => $now);
                if (!$this->cacheStore->write($statePath, $state)) {
                    throw new RateLimitExceeded(1);
                }
                throw new RateLimitExceeded((int)ceil((1.0 - $tokens) / $refillPerSecond));
            }

            $state['buckets'][$bucket] = array('tokens' => $tokens - 1.0, 'updated_at' => $now);
            if (!$this->cacheStore->write($statePath, $state)) {
                throw new RateLimitExceeded(1);
            }
        } finally {
            $this->cacheStore->releaseRefreshLock($lockHandle);
        }
    }
}
