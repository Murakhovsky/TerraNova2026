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
 * Read-only, bounded candidate quote observations. NOT canonical trusted MarketState.
 * No cross-token net edge is calculated and no opportunity is created.
 */
final readonly class CrossVenueQuoteSampler
{
    private const MAX_AGE_SECONDS = 900;
    private const MAX_ROWS = 80;

    public function __construct(private MarketJsonHttpClientInterface $http) {}

    /** @param array<string,mixed> $discovery @return array<string,mixed> */
    public function observe(string $organizationId, array $discovery): array
    {
        if ($organizationId === '' || ($discovery['dataset']??null)!=='cross_venue_candidate_discovery'
            || !is_array($discovery['rows']??null) || count($discovery['rows']) > self::MAX_ROWS) {
            throw new InvalidArgumentException('No eligible bounded discovery evidence for quoting.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {$asOf=new DateTimeImmutable((string)($discovery['as_of_utc']??''));}
        catch(Throwable){throw new InvalidArgumentException('Discovery time is not valid.');}
        $age=$now->getTimestamp()-$asOf->getTimestamp();
        if ($age < -60 || $age > self::MAX_AGE_SECONDS) {
            throw new InvalidArgumentException('STALE_DISCOVERY: refresh the provider catalog first.');
        }

        $targets=['BINANCE'=>[],'BYBIT'=>[],'KRAKEN'=>[]];
        foreach($discovery['rows'] as $row) {
            if (!is_array($row) || !is_array($row['venues']??null)) continue;
            foreach($row['venues'] as $venue) {
                if(!is_array($venue))continue;
                $market=(string)($venue['venue']??'');
                if(!array_key_exists($market,$targets)) continue;
                if(!in_array(($venue['status']??''),['LISTED_EXACT_TOKEN_SYMBOL','DISTINCT_TOKEN_REVIEW_REQUIRED'],true))continue;
                $symbol=(string)($venue['symbol']??'');
                if(preg_match('/^[A-Za-z0-9._-]{2,80}$/D',$symbol)!==1)continue;
                $targets[$market][$symbol]=$market==='KRAKEN'
                    ? (string)($venue['pair_key']??'') : $symbol;
            }
        }

        $observed=[];
        $health=[];
        foreach(['BINANCE','BYBIT','KRAKEN'] as $name) {
            if($targets[$name]===[]) {
                $health[$name]=['status'=>'NO_MATCHED_SYMBOLS','sample_count'=>0];
                continue;
            }
            // Legacy Kraken snapshots lack internal pair keys. Never guess mappings.
            if($name==='KRAKEN' && count(array_filter($targets[$name],static fn($v)=>$v===''))>0) {
                $health[$name]=['status'=>'REFRESH_DISCOVERY_REQUIRED','sample_count'=>0];
                continue;
            }
            try {
                [$url,$host]=$this->endpoint($name,$targets[$name]);
                $body=$this->http->get($organizationId,'capital_markets.quote_preview.'.strtolower($name),
                    $url,[],[$host]);
                $receivedAt=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM);
                $quotes=$this->parse($name,$body,$targets[$name]);
                $observed[$name]=$quotes;
                $health[$name]=['status'=>'AVAILABLE','sample_count'=>count($quotes),
                    'received_at_utc'=>$receivedAt,
                    'evidence_sha256'=>hash('sha256',$body)];
            } catch(Throwable $error) {
                $health[$name]=['status'=>'UNAVAILABLE','sample_count'=>0,
                    'reason'=>mb_substr($error->getMessage(),0,200)];
            }
        }

        $rows=[];
        foreach($discovery['rows'] as $row) {
            if(!is_array($row)||!is_array($row['candidate']??null))continue;
            $listings=[];
            foreach(($row['venues']??[]) as $listing) {
                if(!is_array($listing))continue;
                $venue=(string)($listing['venue']??'');
                if(!in_array($venue,['BINANCE','BYBIT','KRAKEN'],true))continue;
                $symbol=(string)($listing['symbol']??'');
                $quote=$observed[$venue][$symbol]??null;
                $status=($listing['status']??null);
                $listing['quote_status']=!in_array($status,['LISTED_EXACT_TOKEN_SYMBOL','DISTINCT_TOKEN_REVIEW_REQUIRED'],true)
                    ? 'NOT_ELIGIBLE'
                    : (($health[$venue]['status']??'UNAVAILABLE')!=='AVAILABLE'
                        ? (string)($health[$venue]['status']??'UNAVAILABLE')
                        : ($quote===null?'NO_VALID_BBO':'OBSERVED'));
                $listing['quote_observation']=$quote;
                $listings[]=$listing;
            }
            $rows[]=['candidate'=>$row['candidate'],'venues'=>$listings,
                'economic_link'=>'UNVERIFIED','executable'=>false,'expected_net'=>null];
        }
        return ['dataset'=>'cross_venue_quote_observation',
            'universe'=>$discovery['universe'],
            'discovery_as_of_utc'=>$discovery['as_of_utc'],
            'observed_at_utc'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
            'source_health'=>$health,'total'=>count($rows),'rows'=>$rows,
            'constraints'=>['UNVERIFIED_ISSUER_AND_REDEMPTION','NON_EXECUTABLE_PREVIEW',
                'NO_FEE_FX_SLIPPAGE_ADJUSTMENT','RECEIVE_TIME_NOT_PROVIDER_TIME',
                'NO_AUTO_ALLOCATION_OR_TRADE']];
    }

    /** @param array<string,string> $symbols @return array{string,string} */
    private function endpoint(string $venue,array $symbols):array
    {
        return match($venue) {
            'BINANCE'=>['https://data-api.binance.vision/api/v3/ticker/bookTicker',
                'data-api.binance.vision'],
            'BYBIT'=>['https://api.bybit.com/v5/market/tickers?category=spot','api.bybit.com'],
            'KRAKEN'=>['https://api.kraken.com/0/public/Ticker?pair='.rawurlencode(implode(',',array_values($symbols))),
                'api.kraken.com'],
            default=>throw new InvalidArgumentException('Unapproved quote venue.'),
        };
    }

    /**
     * @param array<string,string> $targets
     * @return array<string,array{bid:string,ask:string,bid_quantity:string,ask_quantity:string,quote_currency:string}>
     */
    private function parse(string $venue,string $json,array $targets):array
    {
        try {$data=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(JsonException){throw new InvalidArgumentException('Malformed BBO response.');}
        if(!is_array($data))throw new InvalidArgumentException('BBO response shape invalid.');
        $result=[];
        if($venue==='BINANCE') {
            if(!array_is_list($data))throw new InvalidArgumentException('Binance bulk BBO response invalid.');
            foreach($data as $ticker) {
                if(!is_array($ticker))continue;
                $symbol=(string)($ticker['symbol']??'');
                if(!isset($targets[$symbol]))continue;
                $quote=$this->row($ticker['bidPrice']??null,$ticker['askPrice']??null,
                    $ticker['bidQty']??null,$ticker['askQty']??null,$this->currency($symbol));
                if($quote!==null)$result[$symbol]=$quote;
            }
        }elseif($venue==='BYBIT') {
            if((string)($data['retCode']??'')!=='0'
                || !is_array($data['result']['list']??null)) {
                throw new InvalidArgumentException('Bybit bulk ticker failed.');
            }
            foreach($data['result']['list'] as $ticker) {
                if(!is_array($ticker))continue;
                $symbol=(string)($ticker['symbol']??'');
                if(!isset($targets[$symbol]))continue;
                $quote=$this->row($ticker['bid1Price']??null,$ticker['ask1Price']??null,
                    $ticker['bid1Size']??null,$ticker['ask1Size']??null,$this->currency($symbol));
                if($quote!==null)$result[$symbol]=$quote;
            }
        }else {
            if(($data['error']??null)!==[]||!is_array($data['result']??null)
                || array_is_list($data['result']))throw new InvalidArgumentException('Kraken ticker failed.');
            foreach($targets as $external=>$pairKey) {
                $ticker=$data['result'][$pairKey]??null;
                if(!is_array($ticker))continue;
                $quote=$this->row($ticker['b'][0]??null,$ticker['a'][0]??null,
                    $ticker['b'][2]??null,$ticker['a'][2]??null,$this->currency($external));
                if($quote!==null)$result[$external]=$quote;
            }
        }
        return $result;
    }

    /** @return array{bid:string,ask:string,bid_quantity:string,ask_quantity:string,quote_currency:string}|null */
    private function row(mixed $bid,mixed $ask,mixed $bidQty,mixed $askQty,string $quote):?array
    {
        foreach([$bid,$ask,$bidQty,$askQty] as $value) {
            if(!is_string($value) || strlen($value)>64
                || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value)!==1)return null;
        }
        // Positive best prices and top-level sizes, crossed/locked book is untrusted.
        if($this->nonpositive($bid)||$this->nonpositive($ask)
            || $this->nonpositive($bidQty)||$this->nonpositive($askQty))return null;
        if(\Domains\CapitalMarkets\Domain\Value\Decimal::fromString($bid)->compareTo(
            \Domains\CapitalMarkets\Domain\Value\Decimal::fromString($ask))>=0)return null;
        return ['bid'=>$bid,'ask'=>$ask,'bid_quantity'=>$bidQty,'ask_quantity'=>$askQty,'quote_currency'=>$quote];
    }

    private function nonpositive(string $value):bool {return preg_match('/^0+(?:\.0+)?$/D',$value)===1;}

    private function currency(string $symbol):string
    {
        if(str_ends_with($symbol,'USDT'))return 'USDT';
        if(str_ends_with($symbol,'USD'))return 'USD';
        return 'UNVERIFIED';
    }
}
