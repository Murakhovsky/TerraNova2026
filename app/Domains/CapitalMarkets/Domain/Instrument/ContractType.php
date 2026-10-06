<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Instrument;
enum ContractType:string { case Linear='LINEAR'; case Inverse='INVERSE'; case Other='OTHER'; }
