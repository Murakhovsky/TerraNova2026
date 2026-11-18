<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;
use RuntimeException;
/** Hardcoded trusted HTTPS host; GET only, token never appended to URL. */
final readonly class CurlMetaReadTransport implements MetaReadTransportInterface {
 public function get(string $url,string $accessToken):array {
  if (!preg_match('~^https://graph\.facebook\.com/v[0-9]+\.[0-9]+/~D',$url)
      || $accessToken==='' || strlen($url)>4096) throw new RuntimeException('Invalid Meta read request.');
  $h=curl_init($url);
  if($h===false)throw new RuntimeException('Meta transport unavailable.');
  curl_setopt_array($h,[
   CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
   CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
   CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Accept: application/json'],
   CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_MAXREDIRS=>0,
  ]);
  try{$body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);}
  finally{curl_close($h);}
  if(!is_string($body)||strlen($body)>4194304)throw new RuntimeException('Meta response unavailable or oversized.');
  if($status===429)throw new RuntimeException('Meta rate limit: retry later.');
  if($status<200||$status>=300)throw new RuntimeException('Meta read failed (HTTP '.$status.').');
  $decoded=json_decode($body,true,64);
  if(!is_array($decoded)||array_is_list($decoded))throw new RuntimeException('Invalid Meta response.');
  return $decoded;
 }
}
