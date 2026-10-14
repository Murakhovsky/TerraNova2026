<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;

/**
 * Fail-closed recovery boundary for exchange L2 streams.
 * The transport adapter owns subscription/snapshot fetching and must feed
 * protocol-validated, ordered events into this boundary.
 */
final class L2RecoverySession
{
    private string $status = 'UNINITIALIZED';
    private int $epoch = 0;
    private int $version = 0;
    private ?MarketOrderBook $book = null;
    private ?string $lastSequence = null;
    private ?string $failure = null;
    /** @var list<array{epoch:int,payload:mixed,bytes:int}> */
    private array $buffer = [];
    private int $bufferBytes = 0;

    public function __construct(
        private readonly int $maxBufferedEvents = 10000,
        private readonly int $maxBufferedBytes = 16777216,
    ) {
        if ($maxBufferedEvents < 1 || $maxBufferedBytes < 1) {
            throw new DomainException('Recovery buffer limits must be positive.');
        }
    }

    public function begin(): int
    {
        ++$this->epoch;
        $this->status = 'BUFFERING';
        $this->book = null;
        $this->lastSequence = null;
        $this->failure = null;
        $this->buffer = [];
        $this->bufferBytes = 0;
        return $this->epoch;
    }

    public function buffer(int $epoch, mixed $payload, int $bytes): void
    {
        $this->requireEpoch($epoch);
        if ($this->status !== 'BUFFERING' && $this->status !== 'SYNCING') {
            throw new DomainException('Cannot buffer outside synchronization.');
        }
        if ($bytes < 0 || count($this->buffer) >= $this->maxBufferedEvents
            || $bytes > $this->maxBufferedBytes - $this->bufferBytes) {
            $this->invalidate('BUFFER_OVERFLOW');
            throw new DomainException('L2 delta buffer overflow; fresh recovery required.');
        }
        $this->buffer[] = ['epoch' => $epoch, 'payload' => $payload, 'bytes' => $bytes];
        $this->bufferBytes += $bytes;
    }

    /** @return list<array{epoch:int,payload:mixed,bytes:int}> */
    public function drain(int $epoch): array
    {
        $this->requireEpoch($epoch);
        if ($this->status !== 'BUFFERING' && $this->status !== 'SYNCING') {
            throw new DomainException('Cannot drain outside synchronization.');
        }
        $this->status = 'SYNCING';
        $events = $this->buffer;
        $this->buffer = [];
        $this->bufferBytes = 0;
        return $events;
    }

    /**
     * Call only after venue-specific snapshot/delta reconciliation succeeds.
     * A caller must not use this method to bypass protocol validation.
     */
    public function publishVerified(int $epoch, MarketOrderBook $book, string $sequence): void
    {
        $this->requireEpoch($epoch);
        if (!in_array($this->status, ['SYNCING', 'TRUSTED'], true)) {
            throw new DomainException('Verified publication requires synchronized state.');
        }
        if ($sequence === '' || !ctype_digit($sequence)) {
            $this->invalidate('INVALID_SEQUENCE');
            throw new DomainException('Invalid L2 sequence.');
        }
        if ($this->lastSequence !== null && $this->compareUnsigned($sequence, $this->lastSequence) < 0) {
            $this->invalidate('SEQUENCE_ROLLBACK');
            throw new DomainException('L2 sequence rolled back.');
        }
        if ($this->buffer !== []) {
            $this->invalidate('PENDING_DELTAS_AT_PUBLICATION');
            throw new DomainException('Cannot publish L2 while buffered deltas remain.');
        }
        $this->book = $book;
        $this->lastSequence = $sequence;
        ++$this->version;
        $this->status = 'TRUSTED';
    }

    public function invalidateIfCurrent(int $epoch, string $reason): bool
    {
        if ($epoch !== $this->epoch) return false;
        $this->invalidate($reason);
        return true;
    }

    public function invalidate(string $reason): void
    {
        $this->status = 'INVALID';
        $this->failure = $reason;
        $this->lastSequence = null;
        $this->book = null;
        $this->buffer = [];
        $this->bufferBytes = 0;
    }


    /** @return list<array{U:string,u:string}> */
    public function bufferedSequenceRanges(int $epoch): array
    {
        $this->requireEpoch($epoch);
        if (!in_array($this->status, ['BUFFERING','SYNCING'], true)) return [];
        $ranges = [];
        foreach ($this->buffer as $entry) {
            $payload = $entry['payload'];
            if (!is_array($payload) || !isset($payload['U'], $payload['u'])
                || !is_string($payload['U']) || !is_string($payload['u'])) {
                throw new DomainException('Unexpected L2 sequence buffer entry.');
            }
            $ranges[] = ['U'=>$payload['U'], 'u'=>$payload['u']];
        }
        return $ranges;
    }

    public function status(): string { return $this->status; }

    public function trustedBook(int $epoch): ?MarketOrderBook
    {
        return $epoch === $this->epoch && $this->status === 'TRUSTED' ? $this->book : null;
    }

    /** @return array{status:string,epoch:int,version:int,sequence:?string,failure:?string,buffered:int} */
    public function diagnostics(): array
    {
        return [
            'status' => $this->status, 'epoch' => $this->epoch,
            'version' => $this->version, 'sequence' => $this->lastSequence,
            'failure' => $this->failure, 'buffered' => count($this->buffer),
        ];
    }

    private function requireEpoch(int $epoch): void
    {
        if ($epoch !== $this->epoch) {
            throw new DomainException('Stale L2 session epoch.');
        }
    }

    private function compareUnsigned(string $a, string $b): int
    {
        $a = ltrim($a, '0'); $b = ltrim($b, '0');
        $a = $a === '' ? '0' : $a; $b = $b === '' ? '0' : $b;
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }
}
