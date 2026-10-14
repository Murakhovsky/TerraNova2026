<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
foreach (['L2ReconnectPolicy', 'L2StreamSupervisor', 'L2ManagedStreamRunner', 'L2OperationalMetrics'] as $name) {
    require_once $root.$name.'.php';
}
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ReconnectPolicy;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2StreamSupervisor;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ManagedStreamRunner;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2OperationalMetrics;

$policy = new L2ReconnectPolicy(100, 400, 3);
$metrics = new L2OperationalMetrics();
$runner = new L2ManagedStreamRunner(new L2StreamSupervisor($policy), $policy, $metrics);
$attempts = [];
$delays = [];
$books = [];
$events = [];
$count = $runner->run(
    static function (int $epoch, callable $publish) use (&$attempts): int {
        $attempts[] = $epoch;
        if (count($attempts) === 1) {
            $publish('first');
            throw new DomainException('Disconnected after first book.');
        }
        $publish('second');
        return 1;
    },
    static function (string $book) use (&$books): void { $books[] = $book; },
    static function (int $delay) use (&$delays): void { $delays[] = $delay; },
    null,
    static function (string $event, array $context) use (&$events): void { $events[] = $event; }
);
if ($metrics->snapshot()['connections'] !== 2 || $metrics->snapshot()['reconnects'] !== 1
    || $metrics->snapshot()['publications'] !== 2 || $metrics->snapshot()['failures'] !== 1
    || $count !== 2 || $books !== ['first','second'] || $delays !== [100]
    || $events !== ['DEGRADED','RECOVERED']
    || count(array_unique($attempts)) !== 2 || $runner->diagnostics()['state'] !== 'STOPPED') {
    throw new RuntimeException('Managed runner reconnect regression.');
}

$failed = false;
$exhaustionEvents = [];
$failRunner = new L2ManagedStreamRunner(new L2StreamSupervisor($policy), $policy);
try {
    $failRunner->run(
        static function (int $epoch, callable $publish): int {
            throw new DomainException('Persistent failure.');
        },
        static function (mixed $book): void { throw new RuntimeException('Unexpected book.'); },
        static function (int $delay): void {},
        null,
        static function (string $event, array $context) use (&$exhaustionEvents): void { $exhaustionEvents[] = $event; }
    );
} catch (DomainException $error) {
    $failed = $error->getPrevious() instanceof DomainException;
}
if (!$failed || $exhaustionEvents !== ['DEGRADED','DEGRADED','DEGRADED','EXHAUSTED']
    || $failRunner->diagnostics()['state'] !== 'STOPPED') {
    throw new RuntimeException('Managed runner did not fail closed.');
}

$stop = false;
$stoppedBooks = [];
$stopRunner = new L2ManagedStreamRunner(new L2StreamSupervisor($policy), $policy);
$count = $stopRunner->run(
    static function (int $epoch, callable $publish): int {
        $publish('first');
        $publish('blocked');
        return 2;
    },
    static function (string $book) use (&$stoppedBooks, &$stop): void {
        $stoppedBooks[] = $book;
        $stop = true;
    },
    static function (int $delay): void {},
    static function () use (&$stop): bool { return $stop; }
);
if ($count !== 1 || $stoppedBooks !== ['first'] || $stopRunner->diagnostics()['state'] !== 'STOPPED') {
    throw new RuntimeException('Managed runner graceful stop regression.');
}

echo "L2 managed runner smoke passed\n";
