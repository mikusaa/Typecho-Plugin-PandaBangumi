<?php

namespace TypechoPlugin\PandaBangumi;

final class RefreshFailure extends \RuntimeException
{
    private function __construct(
        string $reason,
        private int $status,
        private string $errorCode,
        private int $retryAfter,
        private int $upstreamStatus = 0
    ) {
        parent::__construct($reason);
        $this->retryAfter = max(0, $retryAfter);
    }

    public static function transient(string $reason, int $upstreamStatus = 0, int $retryAfter = 30): self
    {
        return new self($reason, 503, 'refresh_failed', $retryAfter, $upstreamStatus);
    }

    public static function budgetExceeded(): self
    {
        return new self('PandaBangumi refresh budget exceeded', 503, 'refresh_failed', 1);
    }

    public static function notFound(): self
    {
        return new self('PandaBangumi Subject not found', 404, 'not_found', 0, 404);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function upstreamStatus(): int
    {
        return $this->upstreamStatus;
    }
}
