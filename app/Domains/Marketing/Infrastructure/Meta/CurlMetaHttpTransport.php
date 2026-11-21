<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use RuntimeException;

/** Only the fixed Meta Graph host is allowed; never follow provider-supplied paging URLs. */
final class CurlMetaHttpTransport implements MetaHttpTransport
{
    public function get(string $url,string $accessToken):array
    {
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||($parts['host']??'')!=='graph.facebook.com'
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||isset($parts['port'])){
            throw new RuntimeException('Unapproved Meta endpoint.');
        }
        if(!function_exists('curl_init'))throw new RuntimeException('Meta adapter requires cURL.');
        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('Meta transport unavailable.');
        try {
            curl_setopt_array($curl,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_HEADER=>false,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_TIMEOUT=>20,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Accept: application/json'],
                CURLOPT_USERAGENT=>'COS-Marketing/0.1',
            ]);
            $body=curl_exec($curl);
            $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if(!is_string($body))throw new RuntimeException('Meta request transport failure.');
            if(strlen($body)>2097152)throw new RuntimeException('Meta response exceeded size limit.');
            $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($data))throw new RuntimeException('Meta returned invalid JSON.');
            if($status===429||$status>=500)throw new RuntimeException('Meta temporarily unavailable; read may be retried.');
            if($status<200||$status>=300||isset($data['error']))throw new RuntimeException('Meta request denied or malformed; inspect connection permissions.');
            return $data;
        } catch(\JsonException) {
            throw new RuntimeException('Meta returned invalid JSON.');
        } finally {
            curl_close($curl);
        }
    }
}
