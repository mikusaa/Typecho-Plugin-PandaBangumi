<?php

namespace TypechoPlugin\PandaBangumi;

final class UpstreamGate
{
    private const CONCURRENCY_SLOTS = 2;

    public function __construct(
        private CacheStore $cacheStore,
        private RateLimiter $rateLimiter
    ) {
    }

    public function api(callable $request): mixed
    {
        return $this->run('api', $request);
    }

    public function cover(callable $request): mixed
    {
        return $this->run('cover', $request);
    }

    private function run(string $bucket, callable $request): mixed
    {
        $slot = $this->cacheStore->acquireConcurrencySlot('upstream', self::CONCURRENCY_SLOTS);
        if ($slot === false) {
            throw new RateLimitExceeded(1);
        }

        try {
            if ($bucket === 'cover') {
                $this->rateLimiter->consumeCover();
            } else {
                $this->rateLimiter->consumeApi();
            }
            return $request();
        } finally {
            $this->cacheStore->releaseRefreshLock($slot);
        }
    }
}
