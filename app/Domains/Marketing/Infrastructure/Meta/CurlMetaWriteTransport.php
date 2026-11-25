<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use DomainException;
use RuntimeException;

/** Fixed Graph host/version, explicit edge allowlist, no redirects or retries. */
final class CurlMetaWriteTransport implements MetaWriteTransport
{
    public function request(string $method,string $path,array $fields,string $token):array
    {
        if(!in_array($method,['GET','POST'],true)
            ||!preg_match('~^(?:act_[0-9]{1,30}/(?:campaigns|adsets|ads)|[0-9]{1,30}(?:/(?:adsets|ads))?)$~D',$path)
            ||($method==='POST'&&preg_match('~^[0-9]+/~',$path))
            ||$token===''||strlen($token)>16384||preg_match('/[\\x00-\\x20\\x7f]/',$token)){
            throw new DomainException('Unapproved Meta write transport scope.');
        }
        foreach($fields as $key=>$value){
            if(!is_string($key)||!preg_match('/^[a-z_]{1,50}$/D',$key)||$key==='access_token'
                ||(!is_string($value)&&!is_int($value)))throw new DomainException('Invalid Meta transport field.');
        }
        $encoded=http_build_query($fields,'','&',PHP_QUERY_RFC3986);
        if(strlen($encoded)>32768)throw new DomainException('Meta request exceeds bounds.');
        $url='https://graph.facebook.com/v27.0/'.$path.($method==='GET'&&$encoded!==''?'?'.$encoded:'');
        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('Meta transport unavailable.');
        $body='';
        try{
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
                    if(strlen($body)+strlen($chunk)>262144)return 0;
                    $body.=$chunk;return strlen($chunk);
                }]);
            if($method==='POST')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded]);
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($ok===false||$status<200||$status>=300)throw new RuntimeException('Meta operation uncertain or denied; no blind retry.');
            $decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($decoded)||array_is_list($decoded)||isset($decoded['error']))throw new RuntimeException('Malformed Meta operation response.');
            return $decoded;
        }catch(\JsonException){throw new RuntimeException('Malformed Meta JSON.');}
        finally{curl_close($curl);}
    }
}
