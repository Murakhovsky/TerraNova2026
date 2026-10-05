<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

enum InstrumentIdentifierType: string
{
    case Ticker = 'TICKER';
    case Isin = 'ISIN';
    case Cusip = 'CUSIP';
    case Figi = 'FIGI';
    case ExchangeSymbol = 'EXCHANGE_SYMBOL';
    case ContractAddress = 'CONTRACT_ADDRESS';
    case ProviderId = 'PROVIDER_ID';
}
