<?php

namespace TypechoPlugin\PandaBangumi;

final class RefreshBudget
{
    public const TOTAL_MILLISECONDS = 8000;
    public const CONNECT_TIMEOUT_MILLISECONDS = 2000;
    public const REQUEST_TIMEOUT_MILLISECONDS = 4000;

    /** @var callable */
    private $clock;
    private int $deadline;

    public function __construct(
        int $totalMilliseconds = self::TOTAL_MILLISECONDS,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
        $this->deadline = $this->now() + max(1, $totalMilliseconds) * 1000000;
    }

    private function now(): int
    {
        return (int)($this->clock)();
    }

    public function remainingMilliseconds(): int
    {
        return max(0, (int)floor(($this->deadline - $this->now()) / 1000000));
    }

    public function requireTime(): void
    {
        if ($this->remainingMilliseconds() <= 0) {
            throw RefreshFailure::budgetExceeded();
        }
    }

    public function requestTimeoutMilliseconds(): int
    {
        $this->requireTime();
        return max(1, min(self::REQUEST_TIMEOUT_MILLISECONDS, $this->remainingMilliseconds()));
    }

    public function connectTimeoutMilliseconds(): int
    {
        return max(1, min(self::CONNECT_TIMEOUT_MILLISECONDS, $this->requestTimeoutMilliseconds()));
    }
}
