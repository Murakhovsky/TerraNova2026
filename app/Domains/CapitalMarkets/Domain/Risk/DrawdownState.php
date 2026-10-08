<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum DrawdownState:string { case Normal='NORMAL'; case Caution='CAUTION'; case ReducedRisk='REDUCED_RISK'; case StopNewRisk='STOP_NEW_RISK'; case Emergency='EMERGENCY'; }
