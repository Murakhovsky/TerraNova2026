<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DomainException;
use JsonException;
use RuntimeException;

/** No retries, redirects, caller URLs or raw remote error messages. */
final class CurlHermesAnalystTransport implements HermesAnalystTransport
{
    public function get(string $url,string $token):array
    {
        return $this->request($url,$token,null,null);
    }

    public function post(string $url,string $token,string $key,array $payload):array
    {
        return $this->request($url,$token,$key,$payload);
    }

    private function request(string $url,string $token,?string $key,?array $payload):array
    {
        $parts=parse_url($url);
        $path=(string)($parts['path']??'');
        // A second explicitly allowed READ diagnostic only. The only POST
        // remains the approved advisory Chat Completions path.
        $suffix=$payload===null?(str_ends_with($path,'/v1/capabilities')?'capabilities':'toolsets'):'chat/completions';
        if(!is_array($parts)||($parts['scheme']??null)!=='https'||!isset($parts['host'])
            ||!preg_match('~^/p/[a-zA-Z0-9_-]{1,64}/v1/'.preg_quote($suffix,'~').'$~D',(string)($parts['path']??''))
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||isset($parts['port'])
            ||$token===''||strlen($token)>4096||preg_match('/[\\x00-\\x20\\x7f]/',$token)
            ||($key!==null&&!preg_match('/^cos-mkt-[a-f0-9]{64}$/D',$key))){
            throw new DomainException('Unapproved Hermes request configuration.');
        }
        $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
        if($payload!==null){$headers[]='Content-Type: application/json';$headers[]='Idempotency-Key: '.$key;}
        if(!function_exists('curl_init'))throw new RuntimeException('Hermes transport requires cURL.');
        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('Hermes transport unavailable.');
        $body='';
        try{
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
                    if(strlen($body)+strlen($chunk)>262144)return 0;
                    $body.=$chunk;return strlen($chunk);
                }]);
            if($payload!==null){
                $encoded=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if(strlen($encoded)>32768)throw new DomainException('Hermes request too large.');
                curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded]);
            }
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($ok===false||$status<200||$status>=300){
                throw new RuntimeException('Hermes request failed or uncertain; reconcile before retrying.');
            }
            // Associative decoding otherwise conflates the empty JSON object with an empty list.
            $shape=ltrim($body);
            if(!str_starts_with($shape,$suffix==='toolsets'?'[':'{'))throw new RuntimeException('Unexpected Hermes response shape.');
            $data=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($data)||isset($data['error']))throw new RuntimeException('Invalid Hermes response.');
            return $data;
        }catch(JsonException){
            throw new RuntimeException('Invalid Hermes JSON.');
        }finally{curl_close($curl);}
    }
}
