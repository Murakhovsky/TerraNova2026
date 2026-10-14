<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Throwable;

/**
 * Executes bounded connection sessions with a fresh recovery epoch on each retry.
 * A session must call publish() only after the worker has verified the order book.
 * The supervisor revokes trust immediately on disconnect or ingestion failure.
 */
final readonly class L2ManagedStreamRunner
{
    public function __construct(
        private L2StreamSupervisor $supervisor,
        private L2ReconnectPolicy $policy,
        private ?L2OperationalMetrics $metrics = null,
    ) {}

    /**
     * @param callable(int, callable(mixed):void):int $session
     * @param callable(mixed):bool|void $publish
     * @param callable(int):void|null $sleep
     * @param callable():bool|null $shouldStop
     * @param callable(string,array<string,mixed>):void|null $onTransition
     * @return int number of successful publications
     */
    public function run(
        callable $session,
        callable $publish,
        ?callable $sleep = null,
        ?callable $shouldStop = null,
        ?callable $onTransition = null,
    ): int {
        $sleep ??= static function (int $ms): void { usleep($ms * 1000); };
        $shouldStop ??= static fn(): bool => false;
        $total = 0;
        $incidentOpen = false;
        try {
            for ($attempt = 1; $attempt <= $this->policy->maxAttempts(); ++$attempt) {
                if ($shouldStop()) return $total;
                $epoch = $this->supervisor->start();
                $this->metrics?->connection($attempt);
                $this->supervisor->connected($epoch);
                try {
                    $session($epoch, function (mixed $book) use ($epoch, $publish, &$total, &$incidentOpen, $onTransition, $shouldStop): void {
                        if ($shouldStop()) throw new L2GracefulStop();
                        if (!$this->supervisor->mayPublish($epoch)) {
                            $this->supervisor->verified($epoch);
                        }
                        if (!$this->supervisor->mayPublish($epoch)) {
                            throw new DomainException('Untrusted L2 publication epoch.');
                        }
                        $accepted = $publish($book);
                        if ($accepted === false) return;
                        $this->metrics?->publication();
                        ++$total;
                        if ($incidentOpen) {
                            if ($onTransition !== null) $onTransition('RECOVERED', [
                                'epoch'=>$epoch, 'reason'=>'Verified ingestion resumed.',
                                'supervisor'=>$this->supervisor->diagnostics(),
                                'metrics'=>$this->metrics?->snapshot(),
                            ]);
                            $incidentOpen = false;
                        }
                    });
                    // A finite session is a clean boundary; do not reconnect forever.
                    return $total;
                } catch (L2GracefulStop) {
                    return $total;
                } catch (Throwable $error) {
                    if ($shouldStop()) return $total;
                    $delay = $this->supervisor->failure($epoch, $error->getMessage());
                    $this->metrics?->failure($error->getMessage());
                    $incidentOpen = true;
                    if ($onTransition !== null) $onTransition('DEGRADED', [
                        'epoch'=>$epoch, 'attempt'=>$attempt, 'reason'=>$error->getMessage(),
                        'supervisor'=>$this->supervisor->diagnostics(),
                        'metrics'=>$this->metrics?->snapshot(),
                    ]);
                    if ($attempt === $this->policy->maxAttempts()) {
                        if ($onTransition !== null) $onTransition('EXHAUSTED', [
                            'epoch'=>$epoch, 'attempt'=>$attempt, 'reason'=>$error->getMessage(),
                            'supervisor'=>$this->supervisor->diagnostics(),
                            'metrics'=>$this->metrics?->snapshot(),
                        ]);
                        throw new DomainException('Managed L2 reconnect budget exhausted.', 0, $error);
                    }
                    if ($shouldStop()) return $total;
                    $sleep($delay);
                }
            }
            throw new DomainException('Managed L2 reconnect budget exhausted.');
        } finally {
            $this->supervisor->stop();
        }
    }

    public function diagnostics(): array
    {
        return $this->supervisor->diagnostics() + ['metrics'=>$this->metrics?->snapshot()];
    }
}

/** Internal graceful termination signal, never treated as an exchange failure. */
final class L2GracefulStop extends \RuntimeException {}
