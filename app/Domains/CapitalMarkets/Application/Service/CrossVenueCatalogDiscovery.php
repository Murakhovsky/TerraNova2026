<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * A read-only discovery snapshot, NOT an executable trading signal.
 * Provider catalogs only attest that a trading symbol exists. The same
 * underlying does not imply same token/issuer/redemption rights.
 */
final readonly class CrossVenueCatalogDiscovery
{
    private const SOURCES = [
        'BINANCE'=>'https://data-api.binance.vision/api/v3/exchangeInfo',
        'BYBIT'=>'https://api.bybit.com/v5/market/instruments-info?category=spot',
        'KRAKEN'=>'https://api.kraken.com/0/public/AssetPairs',
    ];

    public function __construct(private MarketJsonHttpClientInterface $http) {}

    /**
     * @param list<array{underlying:string,token:string,market:string,asset_type:string,leverage:string,risk_class:string}> $candidates
     * @return array<string,mixed>
     */
    public function scan(string $organizationId, array $candidates): array
    {
        if ($organizationId === '' || $candidates === [] || count($candidates) > 500) {
            throw new InvalidArgumentException('Invalid cross-venue discovery scope.');
        }
        $catalogs = [];
        $health = [];
        foreach (self::SOURCES as $name=>$url) {
            try {
                $host = (string)parse_url($url, PHP_URL_HOST);
                $body = $this->http->get($organizationId, 'capital_markets.discovery.'.strtolower($name), $url, [], [$host]);
                if (strlen($body) > 8000000) throw new InvalidArgumentException('Provider catalog exceeds size cap.');
                $catalogs[$name] = self::catalog($name, $body);
                $health[$name] = [
                    'state'=>'AVAILABLE','count'=>count($catalogs[$name]),
                    'evidence_sha256'=>hash('sha256',$body),
                ];
            } catch (Throwable $e) {
                // No silent upgrade of missing catalog to empty/zero coverage.
                $catalogs[$name] = null;
                $health[$name] = ['state'=>'UNAVAILABLE','count'=>null,'evidence_sha256'=>null,
                    'reason'=>mb_substr($e->getMessage(),0,250)];
            }
        }

        $items = [];
        foreach ($candidates as $candidate) {
            $token = $candidate['token'];
            $underlying = $candidate['underlying'];
            $matches = [];
            foreach (['BINANCE','BYBIT','KRAKEN'] as $venue) {
                if ($catalogs[$venue] === null) {
                    $matches[]=['venue'=>$venue,'status'=>'UNAVAILABLE','symbol'=>null,'quote'=>null,
                        'equivalence'=>'NOT_ESTABLISHED'];
                    continue;
                }
                $same = self::findExact($catalogs[$venue], $token);
                if ($same !== null) {
                    $matches[]=[
                        'venue'=>$venue,'status'=>'LISTED_EXACT_TOKEN_SYMBOL',
                        'symbol'=>$same['symbol'],'quote'=>$same['quote'],
                        'equivalence'=>'UNVERIFIED','provider_status'=>$same['status'],
                        'pair_key'=>$same['pair_key']??null,
                    ];
                    continue;
                }
                // xStocks are a DIFFERENT issued instrument, even where the
                // referenced equity ticker is the same. Discovery is not linkage.
                $other = self::findExact($catalogs[$venue], $underlying.'X');
                $matches[] = $other === null
                    ? ['venue'=>$venue,'status'=>'NOT_LISTED','symbol'=>null,'quote'=>null,
                        'equivalence'=>'NOT_ESTABLISHED']
                    : ['venue'=>$venue,'status'=>'DISTINCT_TOKEN_REVIEW_REQUIRED',
                        'symbol'=>$other['symbol'],'quote'=>$other['quote'],
                        'equivalence'=>'NOT_EQUIVALENT','provider_status'=>$other['status'],
                        'pair_key'=>$other['pair_key']??null];
            }
            $items[]=['candidate'=>$candidate,'venues'=>$matches,'economic_link'=>'UNVERIFIED',
                'executable'=>false,'net_edge'=>null];
        }

        return [
            'dataset'=>'cross_venue_candidate_discovery',
            'universe'=>'BINANCE_BSTOCKS_2026_09_27',
            'as_of_utc'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
            'historical_input_as_of_utc'=>'2026-09-27T17:53:00Z',
            'source_health'=>$health,'total'=>count($items),'rows'=>$items,
            'constraints'=>[
                'NO_AUTOMATIC_IDENTITY_MERGE','NO_AUTOMATIC_ECONOMIC_LINK','NO_TRADE_OR_ALLOCATION',
                'BID_ASK_NOT_COLLECTED','SOURCE_LISTING_NOT_PROOF_OF_TOKEN_BACKING',
                'QUOTE_CURRENCY_REQUIRES_NORMALIZATION','HISTORICAL_UNIVERSE_NOT_LIVE_STATUS',
            ],
        ];
    }

    /**
     * @return array<string,array{symbol:string,base:string,quote:string,status:string}>
     */
    public static function catalog(string $provider, string $json): array
    {
        try { $body=json_decode($json,true,64,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (JsonException) { throw new InvalidArgumentException('Invalid provider catalog JSON.'); }
        if (!is_array($body) || array_is_list($body)) throw new InvalidArgumentException('Provider catalog must be an object.');
        $rows = match ($provider) {
            'BINANCE'=>$body['symbols']??null,
            'BYBIT'=>($body['result']['list']??null),
            'KRAKEN'=>$body['result']??null,
            default=>throw new InvalidArgumentException('Unapproved discovery provider.'),
        };
        if ($provider==='BYBIT' && (string)($body['retCode']??'')!=='0')throw new InvalidArgumentException('Bybit catalog returned an error.');
        if ($provider==='KRAKEN' && ($body['error']??null)!==[])throw new InvalidArgumentException('Kraken catalog returned an error.');
        if (!is_array($rows) || count($rows)>20000 || ($provider!=='KRAKEN' && !array_is_list($rows))) {
            throw new InvalidArgumentException('Provider catalog rows missing or oversized.');
        }
        $out=[];
        foreach ($rows as $key=>$row) {
            if (!is_array($row)) continue;
            if ($provider==='BINANCE') {
                $base=(string)($row['baseAsset']??'');$quote=(string)($row['quoteAsset']??'');
                $symbol=(string)($row['symbol']??'');$status=(string)($row['status']??'');
            } elseif ($provider==='BYBIT') {
                $base=(string)($row['baseCoin']??'');$quote=(string)($row['quoteCoin']??'');
                $symbol=(string)($row['symbol']??'');$status=(string)($row['status']??'');
            } else {
                $wsname=(string)($row['wsname']??'');
                $parts=explode('/',$wsname);
                if(count($parts)!==2)continue;
                $base=$parts[0];$quote=$parts[1];
                $symbol=(string)($row['altname']??$key);$status=(string)($row['status']??'online');
            }
            $good=$provider==='BINANCE'?$status==='TRADING':
                ($provider==='BYBIT'?$status==='Trading':$status==='online');
            if (!$good || preg_match('/^[A-Za-z0-9.]{1,40}$/D',$base)!==1
                || !in_array($quote,['USD','USDT'],true)
                || preg_match('/^[A-Za-z0-9._\/-]{2,80}$/D',$symbol)!==1)continue;
            $canonicalBase=strtoupper($base);
            $out[$canonicalBase.'|'.$quote]=['symbol'=>$symbol,'base'=>$canonicalBase,
                'quote'=>$quote,'status'=>$status,'pair_key'=>$provider==='KRAKEN'?(string)$key:null];
        }
        ksort($out,SORT_STRING);
        return $out;
    }

    /** @param array<string,array{symbol:string,base:string,quote:string,status:string}> $catalog
     * @return array{symbol:string,base:string,quote:string,status:string}|null */
    private static function findExact(array $catalog, string $token): ?array
    {
        return $catalog[strtoupper($token).'|USDT']
            ?? $catalog[strtoupper($token).'|USD']
            ?? null;
    }
}
