<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Domain/Service/BinanceL2SequenceValidator.php';
use Domains\CapitalMarkets\Domain\Service\BinanceL2SequenceValidator;
$s = new BinanceL2SequenceValidator(false);
if ($s->acceptFirst('100', '90', '100')) throw new RuntimeException('stale event');
if (!$s->acceptFirst('100', '100', '101')) throw new RuntimeException('bridge');
try { $s->acceptNext('104', '105'); throw new RuntimeException('gap accepted'); } catch (DomainException $e) {}
$f = new BinanceL2SequenceValidator(true);
if (!$f->acceptFirst('100', '100', '101')) throw new RuntimeException('futures bridge');
if (!$f->acceptNext('102', '103', '101')) throw new RuntimeException('futures continuity');
echo "Binance L2 sequence regression passed\n";
