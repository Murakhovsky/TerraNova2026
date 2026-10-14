<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;

/**
 * Single-connection public L2 worker. A process supervisor is responsible for
 * restart/backoff; this worker always closes the socket on failure.
 */
final readonly class L2StreamWorker
{
    public function __construct(
        private L2SubscriptionConfig $config,
        private L2SubscriptionEncoder $encoder,
        private L2WebSocketTransport $transport,
        private BinanceL2StreamProcessor|BybitL2StreamProcessor|KrakenL2StreamProcessor $processor,
        private ?BinanceL2SnapshotClient $snapshotClient = null,
        private ?L2SubscriptionAcknowledgement $acknowledgements = null,
        private ?L2PublicationWatchdog $watchdog = null,
    ) {}

    /**
     * @param callable(MarketOrderBook):void $publish
     * @return int number of trusted publications
     */
    public function run(callable $publish, int $maxMessages = 1000, ?callable $shouldStop = null): int
    {
        $shouldStop ??= static fn(): bool => false;
        if ($shouldStop()) return 0;
        if ($maxMessages < 1 || $maxMessages > 1000000) throw new DomainException('Invalid worker message budget.');
        if (str_starts_with($this->config->venue, 'BINANCE_') && $this->snapshotClient === null) {
            throw new DomainException('Binance worker requires REST snapshot client.');
        }
        $this->processor->begin();
        $published = 0;
        $lastVersion = -1;
        try {
            $this->transport->connect($this->config->websocketUrl());
            $subscribe = $this->encoder->encode($this->config);
            if ($subscribe !== null) $this->transport->sendText($subscribe);
            $snapshotPending = str_starts_with($this->config->venue, 'BINANCE_');
            $snapshot = null;
            $subscriptionReady = $subscribe === null;
            $acknowledgements = $this->acknowledgements ?? new L2SubscriptionAcknowledgement();
            for ($i = 0; $i < $maxMessages; ++$i) {
                if ($shouldStop()) return $published;
                $this->assertNotStale($published);
                $frame = $this->transport->readText();
                $this->assertNotStale($published);
                if (!$subscriptionReady) {
                    if ($acknowledgements->accepted($this->config->venue, $frame)) {
                        $subscriptionReady = true;
                        continue;
                    }
                    $control = json_decode($frame, true, 128, JSON_THROW_ON_ERROR);
                    if (!is_array($control) || array_is_list($control)) {
                        throw new DomainException('Malformed L2 subscription response.');
                    }
                    if (isset($control['topic']) || ($control['channel'] ?? null) === 'book') {
                        throw new DomainException('L2 data arrived before subscription acknowledgement.');
                    }
                    continue;
                }
                $this->processor->receive($frame);
                if ($snapshotPending) {
                    // Fetch after the first diff; retain the recovery buffer while
                    // awaiting a diff that actually bridges the REST snapshot.
                    $snapshot = $this->snapshotClient->fetch($this->config);
                    $snapshotPending = false;
                }
                if ($snapshot !== null && $this->processor->canBridge($snapshot['sequence'])) {
                    $this->processor->reconcile($snapshot);
                    $snapshot = null;
                }
                if ($snapshot !== null && $this->processor->snapshotTooOld($snapshot['sequence'])) {
                    throw new DomainException('Binance snapshot fell behind buffered diffs; reconnect required.');
                }
                $book = $this->processor->trustedBook();
                if ($book === null) continue;
                $diagnostics = $this->processor->diagnostics();
                $version = $diagnostics['version'] ?? null;
                if (!is_int($version)) throw new DomainException('Missing L2 book version.');
                if ($version <= $lastVersion) continue;
                $nowMs = intdiv(hrtime(true), 1000000);
                if ($this->watchdog !== null && $published > 0 && $this->watchdog->isStale($nowMs)) {
                    throw new DomainException('L2 verified publication stalled; reconnect required.');
                }
                if ($shouldStop()) return $published;
                $publish($book);
                $this->watchdog?->published($nowMs);
                $lastVersion = $version;
                ++$published;
            }
            if (!$subscriptionReady) throw new DomainException('L2 subscription acknowledgement missing.');
            if ($snapshot !== null || $snapshotPending) throw new DomainException('Binance snapshot recovery incomplete.');
            return $published;
        } finally {
            $this->watchdog?->reset();
            try {
                $this->processor->begin();
            } finally {
                $this->transport->close();
            }
        }
    }

    private function assertNotStale(int $publications): void
    {
        if ($publications > 0 && $this->watchdog !== null
            && $this->watchdog->isStale(intdiv(hrtime(true), 1000000))) {
            throw new DomainException('L2 verified publication stalled; reconnect required.');
        }
    }
}
