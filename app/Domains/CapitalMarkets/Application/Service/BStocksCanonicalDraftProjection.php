<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use InvalidArgumentException;

/**
 * Maps historical import evidence into DRAFT tokenized-security descriptors.
 * Never equates a bStock with its underlying equity, xStock or another issuer.
 */
final class BStocksCanonicalDraftProjection
{
    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed> Input for CapitalMarketsFoundationBoundary::createInstrument.
     */
    public function createInput(string $organizationId,array $evidence): array
    {
        $row=$evidence['row']??null;
        if ($organizationId==='' || strlen($organizationId)>190 || !is_array($row)) {
            throw new InvalidArgumentException('Invalid canonical draft source.');
        }
        $token=$row['token_symbol']??null;
        $underlying=$row['underlying_symbol']??null;
        $venueSymbol=$row['venue_symbol']??null;
        $factor=$row['declared_leverage']??null;
        $type=$row['declared_instrument_type']??null;
        $sha=$evidence['file_sha256']??null;
        if (!is_string($token)||preg_match('/^[A-Z0-9]{2,24}$/D',$token)!==1
            || !is_string($underlying)||preg_match('/^[A-Z0-9]{1,15}$/D',$underlying)!==1
            || $venueSymbol!==$token.'USDT'
            || !in_array($type,['Stock','ETF'],true)
            || !is_int($factor) || $factor===0 || abs($factor)>5
            || ($type==='Stock' && $factor!==1)
            || !is_string($sha)||preg_match('/^[a-f0-9]{64}$/D',$sha)!==1) {
            throw new InvalidArgumentException('Historical bStock identity/type is ambiguous.');
        }
        $name=$row['issuer_or_instrument_name']??null;
        if (!is_string($name)||$name===''||mb_strlen($name)>190) {
            $name='Unverified bStock '.$token;
        }
        return [
            'id'=>'cm_bstock_'.substr(hash('sha256',$organizationId.'|BINANCE_BSTOCKS_SEED|'.$token),0,28),
            'symbol'=>$token,
            'canonical_symbol'=>$token,
            'name'=>$name,
            'family'=>'tokenized_security',
            'status'=>'DRAFT',
            'currency'=>null,
            'quote_asset'=>null,
            'issuer_reference'=>null,
            'jurisdiction'=>null,
            'primary_venue_reference'=>null,
            'metadata'=>[
                'origin'=>'BINANCE_BSTOCKS_SEED',
                'underlying_reference_symbol'=>$underlying,
                'unverified_venue_symbol'=>$venueSymbol,
                'historical_asset_type'=>$type,
                'historical_leverage_factor'=>$factor,
                'source_sha256'=>$sha,
                'source_as_of'=>$evidence['source_as_of']??null,
                'economic_link_status'=>'UNVERIFIED',
                'identity_verified'=>false,
                'eligible_for_execution'=>false,
            ],
            'identifiers'=>[[
                'type'=>'PROVIDER_ID','source'=>'BINANCE_BSTOCKS_SEED','value'=>$token,
            ]],
        ];
    }
}
