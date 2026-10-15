<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
foreach (['L2ReconnectPolicy','L2StreamSupervisor','L2ManagedStreamRunner','L2ManagedWorker'] as $name) {
    require_once $root.$name.'.php';
}
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2ReconnectPolicy,L2StreamSupervisor,L2ManagedStreamRunner,L2ManagedWorker};
$policy = new L2ReconnectPolicy(1,2,2);
$managed = new L2ManagedWorker(new L2ManagedStreamRunner(new L2StreamSupervisor($policy),$policy));
$attempts = [];
$failed = false;
try {
    $managed->run(
        static function(int $epoch) use (&$attempts): object {
            $attempts[] = $epoch;
            return new stdClass();
        },
        static function($book): void { throw new RuntimeException('Invalid factory published.'); },
        1,
        static function(int $ms): void {}
    );
} catch (DomainException $error) {
    $failed = $error->getPrevious() instanceof DomainException;
}
if (!$failed || count($attempts) !== 2 || $attempts[0] === $attempts[1]
    || $managed->diagnostics()['state'] !== 'STOPPED') {
    throw new RuntimeException('Managed worker factory fail-closed regression.');
}
echo "L2 managed worker smoke passed\n";
