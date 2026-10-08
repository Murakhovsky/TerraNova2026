<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
enum CapitalState:string { case Total='TOTAL'; case Available='AVAILABLE'; case Allocated='ALLOCATED'; case Reserved='RESERVED'; case Deployed='DEPLOYED'; case Locked='LOCKED'; case Margined='MARGINED'; case Unsettled='UNSETTLED'; }
