<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;

/**
 * Bridges managed lifecycle and one-connection workers. A fresh worker and
 * recovery session are mandatory on each reconnect attempt.
 */
final readonly class L2ManagedWorker
{
    public function __construct(private L2ManagedStreamRunner $runner) {}

    /**
     * @param callable(int):L2StreamWorker $workerFactory
     * @param callable(MarketOrderBook):void $publish
     * @param callable(int):void|null $sleep
     * @param callable():bool|null $shouldStop
     * @param callable(string,array<string,mixed>):void|null $onTransition
     */
    public function run(
        callable $workerFactory,
        callable $publish,
        int $messageBudget = 1000,
        ?callable $sleep = null,
        ?callable $shouldStop = null,
        ?callable $onTransition = null,
    ): int {
        if ($messageBudget < 1 || $messageBudget > 1000000) {
            throw new DomainException('Invalid managed worker message budget.');
        }
        return $this->runner->run(
            static function (int $epoch, callable $verified) use ($workerFactory, $messageBudget, $shouldStop): int {
                $worker = $workerFactory($epoch);
                if (!$worker instanceof L2StreamWorker) {
                    throw new DomainException('Managed L2 worker factory returned invalid worker.');
                }
                return $worker->run($verified, $messageBudget, $shouldStop);
            },
            $publish,
            $sleep,
            $shouldStop,
            $onTransition,
        );
    }

    public function diagnostics(): array { return $this->runner->diagnostics(); }
}
