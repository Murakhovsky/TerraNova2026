<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Bounded retry wrapper. Each attempt must create a fresh recovery epoch.
 * Fatal exhaustion is propagated; never silently promotes stale market data.
 */
final readonly class L2RetryRunner
{
    public function __construct(private L2ReconnectPolicy $policy) {}

    /** @param callable(int):mixed $attempt @param callable(int):void|null $sleep */
    public function run(callable $attempt, ?callable $sleep = null): mixed
    {
        $sleep ??= static function (int $milliseconds): void { usleep($milliseconds * 1000); };
        for ($number = 1; $number <= $this->policy->maxAttempts(); ++$number) {
            try {
                return $attempt($number);
            } catch (DomainException $error) {
                if ($number === $this->policy->maxAttempts()) {
                    throw new DomainException('L2 reconnect budget exhausted.', 0, $error);
                }
                $sleep($this->policy->delayMs($number));
            }
        }
        throw new DomainException('L2 reconnect budget exhausted.');
    }
}
