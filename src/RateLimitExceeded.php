<?php

namespace TypechoPlugin\PandaBangumi;

final class RateLimitExceeded extends \RuntimeException
{
    public function __construct(private int $retryAfter)
    {
        parent::__construct('PandaBangumi cold request rate limit exceeded');
        $this->retryAfter = max(1, $retryAfter);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
