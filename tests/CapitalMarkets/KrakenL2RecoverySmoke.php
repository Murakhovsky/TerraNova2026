<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Domain/Service/KrakenL2Checksum.php';
require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Domain/Service/KrakenL2BookRecovery.php';

use Domains\CapitalMarkets\Domain\Service\KrakenL2Checksum;
use Domains\CapitalMarkets\Domain\Service\KrakenL2BookRecovery;

function assertL2(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}

$checksum = new KrakenL2Checksum();
$book = new KrakenL2BookRecovery($checksum, 10);
$epoch = $book->begin();
$bids = [['price' => '100.00', 'qty' => '2.00']];
$asks = [['price' => '101.00', 'qty' => '3.00']];
$book->snapshot($epoch, $bids, $asks, $checksum->calculate($asks, $bids));
assertL2($book->trustedBook($epoch) !== null, 'verified snapshot');
$bids2 = [['price' => '100.00', 'qty' => '4.00']];
$book->delta($epoch, $bids2, [], $checksum->calculate($asks, $bids2));
assertL2($book->trustedBook($epoch)['bids'][0]['qty'] === '4.00', 'delta applied');
try {
    $book->delta($epoch, [], [], '0');
    throw new RuntimeException('checksum mismatch must fail');
} catch (DomainException) {
    assertL2($book->trustedBook($epoch) === null, 'checksum fail closed');
}
$next = $book->begin();
assertL2($book->trustedBook($epoch) === null, 'old generation rejected');
$book->snapshot($next, $bids, $asks, $checksum->calculate($asks, $bids));
try {
    $book->delta($next, [['price'=>'102.00','qty'=>'1.00']], [], '0');
    throw new RuntimeException('crossed book must fail');
} catch (DomainException) {
    assertL2($book->trustedBook($next) === null, 'crossed book fail closed');
}
echo "Kraken L2 recovery smoke passed\n";
