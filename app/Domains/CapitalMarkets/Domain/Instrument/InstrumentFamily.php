<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

enum InstrumentFamily: string
{
    case Equity = 'equity';
    case TokenizedSecurity = 'tokenized_security';
    case CryptoAsset = 'crypto_asset';
    case Stablecoin = 'stablecoin';
    case SpotMarket = 'spot_market';
    case Perpetual = 'perpetual';
    case Future = 'future';
    case Option = 'option';
    case FixedIncome = 'fixed_income';
    case TokenizedFixedIncome = 'tokenized_fixed_income';
    case Rwa = 'rwa';
    case Fx = 'fx';
    case Commodity = 'commodity';
    case Index = 'index';
    case Fund = 'fund';
}
