<?php
declare(strict_types=1);

require_once __DIR__.'/L2LiveWorkerFactorySmoke.php';
$root = dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
foreach (['L2ReconnectPolicy','L2StreamSupervisor','L2ManagedStreamRunner','L2ManagedWorker','L2OperationalMetrics'] as $name) {
    require_once $root.$name.'.php';
}

use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{
    L2LiveWorkerFactory,L2SubscriptionConfig,L2ManagedStreamRunner,L2ManagedWorker,
    L2ReconnectPolicy,L2StreamSupervisor,L2OperationalMetrics
};

final class L2FaultDropFixtureTransport extends FixtureL2Transport
{
    public bool $dropAfterVerified = false;
    public bool $closed = false;

    public function readText(): string
    {
        if ($this->dropAfterVerified) {
            $this->dropAfterVerified = false;
            throw new DomainException('SYNTHETIC_SOCKET_LOSS');
        }
        return parent::readText();
    }

    public function close(): void { $this->closed = true; }
}

$policy = new L2ReconnectPolicy(1, 2, 3);
$metrics = new L2OperationalMetrics();
$supervisor = new L2StreamSupervisor($policy);
$runner = new L2ManagedWorker(new L2ManagedStreamRunner($supervisor, $policy, $metrics));
$config = new L2SubscriptionConfig('BYBIT_LINEAR', 'BTCUSDT', 50);
$factory = new L2LiveWorkerFactory();
$epochs = [];
$connections = [];
$books = [];
$transitions = [];
$currentEpoch = 0;
$currentTransport = null;
$injected = false;
$stop = false;
$count = $runner->run(
    static function (int $epoch) use ($factory, $config, &$epochs, &$connections, &$currentEpoch, &$currentTransport) {
        $epochs[] = $currentEpoch = $epoch;
        $connections[] = $currentTransport = new L2FaultDropFixtureTransport();
        return $factory->create($config, new AssetCode('BTC'), new AssetCode('USDT'), 30, $currentTransport);
    },
    static function ($book) use (&$books,&$injected,&$stop,&$currentEpoch,&$currentTransport): void {
        $books[] = ['epoch'=>$currentEpoch,'quantity'=>$book->bids[0]->quantity->value->value()];
        if (!$injected) {
            $injected = true;
            $currentTransport->dropAfterVerified = true;
        } else {
            $stop = true;
        }
    },
    10,
    static function (int $ms): void {},
    static function () use (&$stop):bool { return $stop; },
    static function (string $event, array $details) use (&$transitions): void {
        $transitions[] = [$event,$details['supervisor']['state']];
    },
);
if ($count !== 2 || count($epochs) !== 2 || $epochs[0] === $epochs[1]
    || $books !== [
        ['epoch'=>$epochs[0],'quantity'=>'1'],
        ['epoch'=>$epochs[1],'quantity'=>'1'],
    ]
    || $transitions !== [['DEGRADED','BACKOFF'],['RECOVERED','TRUSTED']]
    || $metrics->snapshot()['reconnects'] !== 1
    || $metrics->snapshot()['publications'] !== 2
    || $metrics->snapshot()['failures'] !== 1
    || !$connections[0]->closed || !$connections[1]->closed
    || $runner->diagnostics()['state'] !== 'STOPPED') {
    throw new RuntimeException('L2 forced socket loss must require a fresh verified recovery epoch.');
}
echo "L2 forced-disconnect recovery smoke passed\n";
