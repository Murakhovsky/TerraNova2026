<?php
declare(strict_types=1);

/**
 * Opt-in network acceptance: real public venue TLS/WebSocket + protocol-verified
 * order-book snapshots, then an intentionally broken socket and a NEW trusted
 * book on a NEW connection/epoch. Does not use the production database.
 *
 * FOS_L2_LIVE_TEST_ENABLED=1 php tests/live/l2_public_reconnect.php --venue=BYBIT_SPOT
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\MarketData\{MarketEventType,MarketOrderBook};
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{
    L2LiveWorkerFactory,L2ManagedStreamRunner,L2ManagedWorker,
    L2OperationalMetrics,L2ReconnectPolicy,L2StreamSupervisor,
    L2SubscriptionConfig,L2WebSocketTransport
};

final class L2LiveInjectedDisconnectTransport extends L2WebSocketTransport
{
    private bool $dropNextRead = false;

    public function injectDisconnectAfterPublication(): void
    {
        $this->dropNextRead = true;
    }

    public function readText(): string
    {
        if ($this->dropNextRead) {
            $this->dropNextRead = false;
            $this->close();
            throw new DomainException('LIVE_TEST_INJECTED_DISCONNECT');
        }
        return parent::readText();
    }
}

if (getenv('FOS_L2_LIVE_TEST_ENABLED') !== '1') {
    fwrite(STDERR, "Live network tests are opt-in: FOS_L2_LIVE_TEST_ENABLED=1\n");
    exit(64);
}

$venue = getopt('', ['venue:'])['venue'] ?? '';
$cases = [
    'BINANCE_SPOT' => ['BTCUSDT', 50],
    'BINANCE_FUTURES' => ['BTCUSDT', 50],
    'BYBIT_SPOT' => ['BTCUSDT', 50],
    'BYBIT_LINEAR' => ['BTCUSDT', 50],
    'KRAKEN_SPOT' => ['BTC/USD', 10],
];
if (!is_string($venue) || !isset($cases[$venue])) {
    fwrite(STDERR, "Use --venue=BINANCE_SPOT|BINANCE_FUTURES|BYBIT_SPOT|BYBIT_LINEAR|KRAKEN_SPOT\n");
    exit(64);
}

[$symbol, $depth] = $cases[$venue];
$config = new L2SubscriptionConfig($venue, $symbol, $depth);
$factory = new L2LiveWorkerFactory();
$policy = new L2ReconnectPolicy(250, 2500, 6);
$metrics = new L2OperationalMetrics();
$supervisor = new L2StreamSupervisor($policy);
$managed = new L2ManagedWorker(new L2ManagedStreamRunner($supervisor, $policy, $metrics));
$epochs = [];
$books = [];
$events = [];
$currentEpoch = null;
$currentTransport = null;
$injected = false;
$stop = false;
$startedAt = microtime(true);

try {
    $published = $managed->run(
        static function (int $epoch) use (
            $factory,$config,&$epochs,&$currentEpoch,&$currentTransport
        ): \Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2StreamWorker {
            $currentEpoch = $epoch;
            $epochs[] = $epoch;
            $currentTransport = new L2LiveInjectedDisconnectTransport(10);
            return $factory->create(
                $config,new AssetCode('BTC'),new AssetCode('USDT'),35,$currentTransport,
            );
        },
        static function (MarketOrderBook $book) use (
            &$books,&$injected,&$stop,&$currentTransport,&$currentEpoch
        ): void {
            if ($book->type !== MarketEventType::OrderBookSnapshot
                || $book->sequence === null || $book->sequence === ''
                || $book->bids === [] || $book->asks === []) {
                throw new DomainException('Live venue delivered no verified full order-book snapshot.');
            }
            $books[] = [
                'epoch'=>$currentEpoch,
                'sequence'=>$book->sequence,
                'bid_levels'=>count($book->bids),
                'ask_levels'=>count($book->asks),
            ];
            if (!$injected) {
                $injected = true;
                $currentTransport->injectDisconnectAfterPublication();
            } else {
                $stop = true;
            }
        },
        100000,
        static function (int $milliseconds): void { usleep($milliseconds * 1000); },
        static function () use (&$stop): bool { return $stop; },
        static function (string $transition, array $context) use (&$events): void {
            $events[] = [
                'type'=>$transition,
                'state'=>$context['supervisor']['state'] ?? null,
                'reason'=>$context['reason'] ?? null,
                'epoch'=>$context['epoch'] ?? null,
            ];
        },
    );
    $backoffVerified = false;
    $recoveredVerified = false;
    foreach ($events as $event) {
        if ($event['type'] === 'DEGRADED'
            && $event['reason'] === 'LIVE_TEST_INJECTED_DISCONNECT'
            && $event['state'] === 'BACKOFF') $backoffVerified = true;
        if ($backoffVerified && $event['type'] === 'RECOVERED'
            && $event['state'] === 'TRUSTED') $recoveredVerified = true;
    }
    if ($published !== 2 || count($books) !== 2 || !$injected
        || $books[0]['epoch'] === $books[1]['epoch']
        || !$backoffVerified || !$recoveredVerified
        || ($managed->diagnostics()['state'] ?? null) !== 'STOPPED'
        || $metrics->snapshot()['reconnects'] < 1
        || $metrics->snapshot()['failures'] < 1) {
        throw new RuntimeException('Live reconnect/trust acceptance assertions failed.');
    }

    echo json_encode([
        'result'=>'PASS',
        'venue'=>$venue,
        'endpoint'=>$config->websocketUrl(),
        'real_websocket'=>true,
        'forced_disconnect'=>true,
        'fresh_verified_publications'=>$published,
        'epochs'=>$epochs,
        'books'=>$books,
        'transitions'=>$events,
        'metrics'=>$metrics->snapshot(),
        'duration_seconds'=>round(microtime(true) - $startedAt, 3),
    ],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR,json_encode([
        'result'=>'FAIL',
        'venue'=>$venue,
        'endpoint'=>$config->websocketUrl(),
        'forced_disconnect_injected'=>$injected,
        'books'=>$books,
        'epochs'=>$epochs,
        'transitions'=>$events,
        'error_type'=>$error::class,
        'error'=>substr($error->getMessage(),0,250),
        'cause'=>substr($error->getPrevious()?->getMessage() ?? '',0,250),
        'duration_seconds'=>round(microtime(true) - $startedAt, 3),
    ],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
}
