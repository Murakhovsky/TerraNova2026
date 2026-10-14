<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\Service\L2RecoverySession;
use Domains\CapitalMarkets\Domain\Value\AssetCode;

/** A new socket and recovery session for every reconnect attempt. */
final readonly class L2LiveWorkerFactory
{
    public function create(L2SubscriptionConfig $config, AssetCode $base, AssetCode $quote, int $staleSeconds): L2StreamWorker
    {
        $session = new L2RecoverySession();
        $frames = new L2FrameDispatch(
            new L2FrameSizeGuard(), new L2ControlFrameFilter(),
            new L2WebSocketFrameRouter($config->venue, $config->symbol, $config->depth), $config->venue,
        );
        $books = new L2DomainBookFactory($base, $quote);
        $processor = match ($config->venue) {
            'BINANCE_SPOT', 'BINANCE_FUTURES' => new BinanceL2StreamProcessor(
                $frames, $books, new BinanceL2RecoveryCoordinator($session, $config->venue === 'BINANCE_FUTURES'), $session,
            ),
            'BYBIT_SPOT', 'BYBIT_LINEAR' => new BybitL2StreamProcessor(
                $frames, $books, new BybitL2RecoveryCoordinator($session), $session,
            ),
            'KRAKEN_SPOT' => new KrakenL2StreamProcessor(
                $frames, $books, new KrakenL2BookRecovery(new KrakenL2Checksum(), $config->depth),
            ),
            default => throw new DomainException('Unsupported L2 venue.'),
        };
        return new L2StreamWorker(
            $config, new L2SubscriptionEncoder(), new L2WebSocketTransport(), $processor,
            str_starts_with($config->venue, 'BINANCE_')
                ? new BinanceL2SnapshotClient(new BinanceL2SnapshotDecoder()) : null,
            new L2SubscriptionAcknowledgement(), new L2PublicationWatchdog($staleSeconds * 1000),
        );
    }
}
