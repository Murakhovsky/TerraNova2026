<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Domain/Service/BinanceL2SequenceValidator.php';

use Domains\CapitalMarkets\Domain\Service\BinanceL2SequenceValidator;

function expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function expectFailure(callable $operation): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Expected sequence validation failure');
}

$spot = new BinanceL2SequenceValidator(false);
expect(!$spot->acceptFirst('100', '99', '100'), 'stale spot event');
expect($spot->acceptFirst('100', '101', '102'), 'spot bridge');
expect($spot->acceptNext('103', '104'), 'spot continuation');
expect(!$spot->acceptNext('102', '104'), 'spot duplicate');
expectFailure(static fn() => $spot->acceptNext('106', '107'));

$futures = new BinanceL2SequenceValidator(true);
expect($futures->acceptFirst('100', '99', '100'), 'futures inclusive snapshot bridge');
expect($futures->acceptNext('101', '102', '100'), 'futures previous update');
expectFailure(static fn() => $futures->acceptNext('103', '104', '99'));

$bad = new BinanceL2SequenceValidator(false);
expectFailure(static fn() => $bad->acceptFirst('100', '103', '102'));
expectFailure(static fn() => $bad->acceptFirst('100', 'x', '102'));
expect($bad->acceptFirst('999999999999999999999', '1000000000000000000000', '1000000000000000000001'), 'large IDs');

echo "Binance L2 sequence smoke passed\n";
