<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use RuntimeException;

/**
 * Tenant-specific imported universe takes precedence over the bundled bootstrap
 * CSV. Historical rows are candidates only, never authoritative live listings.
 */
final readonly class CrossVenueActiveUniverse
{
    public function __construct(
        private BStocksSeedImportRepository $seeds,
        private CrossVenueUniverse $bootstrap,
    ) {}

    /**
     * @return array{rows:list<array{underlying:string,token:string,market:string,asset_type:string,leverage:string,risk_class:string}>,origin:string,source_as_of:?string}
     */
    public function load(string $organizationId,string $bootstrapFile):array
    {
        if ($organizationId==='' || strlen($organizationId)>190) {
            throw new RuntimeException('Invalid tenant universe request.');
        }
        $evidence=$this->seeds->listForCanonicalDraft($organizationId);
        if ($evidence===[]) {
            return ['rows'=>$this->bootstrap->load($bootstrapFile),
                'origin'=>'BOOTSTRAP_CSV','source_as_of'=>'2026-09-27T17:53:00Z'];
        }
        $rows=[];$unique=[];$asOf=null;
        foreach ($evidence as $item) {
            $raw=$item['row'];
            $token=$raw['token_symbol']??null;
            $underlying=$raw['underlying_symbol']??null;
            $market=$raw['venue_symbol']??null;
            $type=$raw['declared_instrument_type']??null;
            $leverage=$raw['declared_leverage']??null;
            $timestamp=$item['source_as_of'];
            if (!is_string($token) || preg_match('/^[A-Z0-9]{2,24}$/D',$token)!==1
                || !is_string($underlying) || preg_match('/^[A-Z0-9]{1,15}$/D',$underlying)!==1
                || !is_string($market) || $market!==$token.'USDT'
                || !in_array($type,['Stock','ETF'],true)
                || !is_int($leverage)||$leverage===0||abs($leverage)>5
                || ($type==='Stock' && $leverage!==1)
                || !is_string($timestamp)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$timestamp)!==1
                || isset($unique[$token])) {
                throw new RuntimeException('Imported seed universe contains inconsistent evidence.');
            }
            $unique[$token]=true;
            $rows[]=['underlying'=>$underlying,'token'=>$token,'market'=>$market,
                'asset_type'=>$type,'leverage'=>(string)$leverage,
                'risk_class'=>($leverage<0 || abs($leverage)!==1)?'LEVERAGED_OR_INVERSE':'STANDARD_CANDIDATE'];
            if ($asOf===null || $asOf<$timestamp) $asOf=$timestamp;
        }
        if (count($rows)>500) throw new RuntimeException('Imported seed universe exceeds 500.');
        return ['rows'=>$rows,'origin'=>'IMPORTED_MYSQL','source_as_of'=>$asOf];
    }
}
