<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/**
 * Spot asset value candidates, never reconciled NAV.
 * SHORT, PERPETUAL and unclassified instruments require collateral and
 * liability accounting, not a naive quantity * mid estimate.
 */
final class PortfolioNavSpotMarkEvidence
{
    /** @param array<string,mixed> $position @param array<string,mixed> $market
     *  @return array<string,mixed>
     */
    public static function inspect(array $position,array $market,string $currency,?DateTimeImmutable $at=null):array
    {
        $fail=static fn(string $reason):array=>['status'=>'BLOCKED','reason'=>$reason,'mark_reconciled'=>false];
        $kind=strtoupper((string)($position['instrument_kind']??''));
        if (!in_array($kind,['SPOT','TOKENIZED_EQUITY'],true)) {
            return $fail('DERIVATIVE_OR_UNCLASSIFIED_POSITION_REQUIRES_MARGIN_ACCOUNTING');
        }
        if (strtoupper((string)($position['side']??''))!=='LONG') {
            return $fail('SHORT_POSITION_REQUIRES_LIABILITY_ACCOUNTING');
        }
        if (($position['contract_multiplier']??null)!==null
            && (string)$position['contract_multiplier']!=='1') {
            return $fail('CONTRACT_MULTIPLIER_REQUIRES_SEPARATE_VALUATION');
        }
        if ((string)($market['quality_status']??'')!=='TRUSTED'
            || (string)($market['mode']??'')!=='LIVE'
            || (string)($market['market_status']??'')!=='OPEN'
            || !empty($market['quality_flags'])
            || !is_string($market['source_timestamp']??null)
            || !is_int($market['state_version']??null)
            || $market['state_version']<1
            || !is_string($market['last_event_fingerprint']??null)
            || preg_match('/^[a-f0-9]{64}$/',$market['last_event_fingerprint'])!==1) {
            return $fail('MARK_QUALITY_OR_PROVENANCE_INVALID');
        }
        $quote=$market['best_quote']??null;
        if (!is_array($quote))return $fail('MARK_BBO_MISSING');
        $bid=$quote['bid_price']??null;
        $ask=$quote['ask_price']??null;
        if (!is_array($bid)||!is_array($ask)
            || strtoupper((string)($bid['quote_asset']??''))!==strtoupper($currency)
            || strtoupper((string)($ask['quote_asset']??''))!==strtoupper($currency)
            || !is_string($quote['mid_price']??null)) {
            return $fail('MARK_CURRENCY_OR_MID_UNVERIFIED');
        }
        try {
            $utc=new DateTimeZone('UTC');
            $now=($at??new DateTimeImmutable('now',$utc))->setTimezone($utc);
            $time=(new DateTimeImmutable($market['source_timestamp'],$utc))->setTimezone($utc);
            $age=$now->getTimestamp()-$time->getTimestamp();
            if ($age<0||$age>30)return $fail('MARK_STALE_OR_FUTURE');
            $qty=Decimal::fromString((string)($position['quantity']??''));
            $mid=Decimal::fromString($quote['mid_price']);
            if (!$qty->isPositive()||!$mid->isPositive())return $fail('QUANTITY_OR_MID_NOT_POSITIVE');
            $value=DecimalMath::multiply($qty,$mid);
            return [
                'status'=>'CANDIDATE',
                'reason'=>'PENDING_POSITION_AND_VENUE_RECONCILIATION',
                'position_id'=>(string)($position['position_id']??''),
                'instrument_id'=>(string)($position['instrument_id']??''),
                'venue_id'=>(string)($position['venue_id']??''),
                'quote_currency'=>strtoupper($currency),
                'quantity'=>$qty->value(),
                'mark_mid'=>$mid->value(),
                'candidate_market_value'=>$value->value(),
                'source_timestamp'=>$time->format(DATE_ATOM),
                'market_state_version'=>$market['state_version'],
                'mark_source_fingerprint'=>$market['last_event_fingerprint'],
                'mark_reconciled'=>false,
            ];
        } catch (Throwable) {
            return $fail('MARK_OR_POSITION_DECIMAL_INVALID');
        }
    }
}
