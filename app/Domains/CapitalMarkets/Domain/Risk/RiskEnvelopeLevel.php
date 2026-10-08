<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum RiskEnvelopeLevel:string { case System='SYSTEM'; case Portfolio='PORTFOLIO'; case Strategy='STRATEGY'; case Venue='VENUE'; case Asset='ASSET'; case Instrument='INSTRUMENT'; case Position='POSITION'; }
