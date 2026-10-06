<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Instrument;
enum MarginMode:string { case Isolated='ISOLATED'; case Cross='CROSS'; }
