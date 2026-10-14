<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use Closure;
use DomainException;
use Throwable;

/**
 * One alert per transition per incident; retries a failed outbox enqueue with
 * the identical notification identity and payload. Never blocks market data.
 */
final class L2AlertLifecycle
{
    private ?string $incidentId = null;
    private int $incidentNumber = 0;
    private string $runToken;
    /** @var array<string,array{incident:string,transition:string,reason:string}> */
    private array $pending = [];
    /** @var array<string,bool> */
    private array $delivered = [];
    /** @var array<string,bool> */
    private array $reportedFailures = [];
    private ?Closure $reportError;

    public function __construct(
        private readonly L2AlertDelivery $delivery,
        private readonly string $organizationId,
        private readonly string $recipientEmail,
        private readonly string $sourceId,
        private readonly string $venue,
        private readonly string $symbol,
        private readonly string $locale = 'uk',
        ?callable $reportError = null,
        ?string $runToken = null,
    ) {
        foreach ([$organizationId,$sourceId,$venue,$symbol] as $identity) {
            if (trim($identity) === '') throw new DomainException('L2 alert stream identity is required.');
        }
        if (!filter_var($recipientEmail,FILTER_VALIDATE_EMAIL)) throw new DomainException('Invalid L2 alert email.');
        if (!in_array($locale,['uk','en'],true)) throw new DomainException('Unsupported L2 alert locale.');
        $this->reportError = $reportError === null ? null : Closure::fromCallable($reportError);
        $this->runToken = $runToken ?? bin2hex(random_bytes(12));
        if ($this->runToken === '') throw new DomainException('Invalid L2 alert run token.');
    }

    public function onTransition(string $transition, string $reason = 'UNKNOWN'): void
    {
        if (!in_array($transition,['DEGRADED','STALE','EXHAUSTED','RECOVERED'],true)) {
            throw new DomainException('Unsupported L2 operational transition.');
        }
        if ($transition !== 'RECOVERED' && $this->incidentId === null) {
            ++$this->incidentNumber;
            $this->incidentId = 'l2-'.substr(hash('sha256',implode('|',[
                $this->organizationId,$this->sourceId,$this->venue,$this->symbol,
                $this->runToken,(string)$this->incidentNumber,
            ])),0,28);
        }
        if ($this->incidentId === null) {
            $this->flush();
            return;
        }
        $key = $this->incidentId.':'.$transition;
        if (!isset($this->pending[$key]) && !isset($this->delivered[$key])) {
            $this->pending[$key] = [
                'incident'=>$this->incidentId,
                'transition'=>$transition,
                'reason'=>$reason,
            ];
        }
        if ($transition === 'RECOVERED') $this->incidentId = null;
        $this->flush();
    }

    /** Attempt to enqueue pending events in incident order. */
    public function flush(): void
    {
        foreach ($this->pending as $key => $event) {
            try {
                $this->delivery->queue(
                    $this->organizationId,$this->recipientEmail,$this->sourceId,
                    $this->venue,$this->symbol,$event['incident'],
                    $event['transition'],$event['reason'],$this->locale,
                );
                $this->delivered[$key] = true;
                unset($this->pending[$key],$this->reportedFailures[$key]);
            } catch (Throwable $error) {
                // A transient Notification/n8n outage must not be mistaken for
                // an exchange failure and trigger an order-book reconnect.
                if (!isset($this->reportedFailures[$key])) {
                    $this->reportedFailures[$key] = true;
                    try { ($this->reportError ?? static function(Throwable $ignored):void {})($error); }
                    catch (Throwable) { /* diagnostics must not break data ingestion */ }
                }
                break;
            }
        }
    }

    public function pendingCount(): int { return count($this->pending); }
}
