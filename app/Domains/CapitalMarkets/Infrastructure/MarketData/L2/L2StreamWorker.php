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
    ) {}

    /**
     * @param callable(MarketOrderBook):void $publish
     * @return int number of trusted publications
     */
    public function run(callable $publish, int $maxMessages = 1000): int
    {
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
            for ($i = 0; $i < $maxMessages; ++$i) {
                $frame = $this->transport->readText();
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
                $publish($book);
                $lastVersion = $version;
                ++$published;
            }
            return $published;
        } finally {
            $this->processor->begin();
            $this->transport->close();
        }
    }
}
