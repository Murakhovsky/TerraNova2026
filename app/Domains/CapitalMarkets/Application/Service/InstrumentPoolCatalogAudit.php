<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\VenueRepository;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use RuntimeException;
use Throwable;

/**
 * Bounded read-only audit of live public exchange catalogs against canonical
 * mappings. A provider listing alone NEVER establishes instrument identity,
 * economic equivalence, execution permission or delisting.
 */
final readonly class InstrumentPoolCatalogAudit
{
    private const SOURCES=[
        'BINANCE'=>'https://data-api.binance.vision/api/v3/exchangeInfo',
        'BYBIT'=>'https://api.bybit.com/v5/market/instruments-info?category=spot',
        'KRAKEN'=>'https://api.kraken.com/0/public/AssetPairs',
    ];

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private VenueRepository $venues,
    ){}

    /** @return array<string,mixed> */
    public function inspect(string $organizationId,string $provider,string $venueId,int $limit=200):array
    {
        if($organizationId===''||trim($organizationId)!==$organizationId
            ||strlen($organizationId)>190||$limit<1||$limit>500){
            throw new DomainException('Invalid instrument pool audit scope.');
        }
        $provider=strtoupper($provider);
        $url=self::SOURCES[$provider]??null;
        if($url===null)throw new DomainException('Unknown instrument discovery provider.');
        $venueKey=VenueId::fromString($venueId);
        if($this->venues->get($organizationId,$venueKey)===null){
            throw new DomainException('Cannot reconcile a non-existent canonical venue.');
        }

        // All venue mappings are read before the request, never mutated by it.
        $mappings=[];
        foreach($this->venues->instruments($organizationId,$venueKey) as $mapping){
            $mappings[]=[
                'venue_symbol'=>$mapping->venueSymbol,
                'instrument_id'=>$mapping->instrumentId->value(),
                'mapping_status'=>$mapping->status->value,
            ];
        }
        if(count($mappings)>20000){
            throw new DomainException('Instrument pool canonical mapping limit exceeded.');
        }
        try{
            $loaded=CrossVenueCatalogDiscovery::loadProviderCatalog(
                $this->http,$organizationId,$provider,
                'capital_markets.instrument_pool.'.strtolower($provider),
            );
            $catalog=$loaded['catalog'];
        }catch(Throwable $error){
            return [
                'provider'=>$provider,'venue_id'=>$venueId,
                'health'=>'UNAVAILABLE','provider_error_type'=>get_class($error),
                'evidence_sha256'=>null,'provider_count'=>null,
                'mapping_count'=>count($mappings),'rows'=>[],
                'counts'=>null,'truncated'=>false,
                'dry_run'=>true,'executable'=>false,
                'policy'=>'NO_DELIST_ON_PROVIDER_FAILURE',
            ];
        }

        $diff=self::reconcile($catalog,$mappings,$limit);
        return [
            'provider'=>$provider,'venue_id'=>$venueId,
            'as_of_utc'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
            'health'=>'AVAILABLE',
            'evidence_sha256'=>$loaded['evidence_sha256'],
            'provider_pages'=>$loaded['pages'],
            'provider_count'=>count($catalog),
            'mapping_count'=>count($mappings),
            ...$diff,
            'dry_run'=>true,'executable'=>false,
            'policy'=>'NO_AUTOMATIC_IDENTITY_OR_DELISTING',
        ];
    }

    /**
     * A pure deterministic diff, suitable for staged periodic discovery.
     * Exact venue_symbol identity is the ONLY automatic match.
     *
     * @param array<string,array<string,mixed>> $catalog
     * @param list<array{venue_symbol:string,instrument_id:string,mapping_status:string}> $mappings
     * @return array{counts:array<string,int>,rows:list<array<string,mixed>>,truncated:bool,mapped_coverage_complete:bool}
     */
    public static function reconcile(array $catalog,array $mappings,int $limit=200):array
    {
        if($limit<1||$limit>500||count($catalog)>20000||count($mappings)>20000){
            throw new DomainException('Instrument pool audit exceeds bounded workload.');
        }
        $bySymbol=[];
        foreach($catalog as $entry){
            $symbol=$entry['symbol']??null;
            $base=$entry['base']??null;
            $quote=$entry['quote']??null;
            if(!is_string($symbol)||!preg_match('/^[A-Za-z0-9._\/-]{2,80}$/D',$symbol)
                ||!is_string($base)||$base===''||!is_string($quote)||$quote===''){
                throw new DomainException('Invalid provider instrument identity in catalog.');
            }
            if(isset($bySymbol[$symbol]))throw new DomainException('Ambiguous duplicate external symbol in provider catalog.');
            $bySymbol[$symbol]=['base'=>$base,'quote'=>$quote];
        }

        $mapped=[];
        foreach($mappings as $mapping){
            $symbol=$mapping['venue_symbol'];
            if($symbol===''||!isset($mapping['instrument_id'],$mapping['mapping_status'])
                ||isset($mapped[$symbol])){
                throw new DomainException('Ambiguous canonical venue instrument mapping.');
            }
            $mapped[$symbol]=$mapping;
        }

        $out=[];
        $counts=[
            'MAPPED_ACTIVE_OBSERVED'=>0,
            'MAPPED_STATUS_REVIEW'=>0,
            'MAPPED_NOT_OBSERVED_REVIEW'=>0,
            'UNMAPPED_REVIEW'=>0,
        ];
        foreach($mapped as $symbol=>$mapping){
            $observed=$bySymbol[$symbol]??null;
            $category=$observed===null?'MAPPED_NOT_OBSERVED_REVIEW':
                ($mapping['mapping_status']==='ACTIVE'?'MAPPED_ACTIVE_OBSERVED':'MAPPED_STATUS_REVIEW');
            $counts[$category]++;
            $out[]=[
                'venue_symbol'=>$symbol,'instrument_id'=>$mapping['instrument_id'],
                'mapping_status'=>$mapping['mapping_status'],
                'observation'=>$category,'base'=>$observed['base']??null,
                'quote'=>$observed['quote']??null,
                'automatically_mutated'=>false,
            ];
        }
        foreach($bySymbol as $symbol=>$details){
            if(isset($mapped[$symbol]))continue;
            $counts['UNMAPPED_REVIEW']++;
            $out[]=[
                'venue_symbol'=>$symbol,'instrument_id'=>null,
                'mapping_status'=>null,'observation'=>'UNMAPPED_REVIEW',
                ...$details,'automatically_mutated'=>false,
            ];
        }
        // Prioritize ALL registered mappings before new candidates. Without
        // this guarantee, alphabetical paging can silently hide a stale mapped
        // symbol behind thousands of unrelated new provider listings.
        usort($out,static function(array $a,array $b):int{
            $aNew=$a['instrument_id']===null?1:0;
            $bNew=$b['instrument_id']===null?1:0;
            return ($aNew<=>$bNew)?:strcmp($a['venue_symbol'],$b['venue_symbol']);
        });
        $total=count($out);
        return [
            'counts'=>$counts,'rows'=>array_slice($out,0,$limit),
            'truncated'=>$total>$limit,
            'mapped_coverage_complete'=>count($mapped)<=$limit,
        ];
    }
}
