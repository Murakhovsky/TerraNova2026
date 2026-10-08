<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum ValuationQuality:string { case Trusted='TRUSTED'; case Degraded='DEGRADED'; case Stale='STALE'; case Unknown='UNKNOWN'; }
