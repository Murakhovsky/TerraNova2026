<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/** Upload bytes only to a narrowly allowed Shopify staging host, never an
 * arbitrary URL, never forwarding the Admin API token to the staging host. */
final class ShopifyStagedImageUploader implements ShopifyStagedImageUploadPort
{
    public function upload(string $url,array $parameters,string $bytes,string $mime,string $filename):void
    {
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'
            ||!in_array($parts['host']??null,['shopify-staged-uploads.storage.googleapis.com','shopify.s3.amazonaws.com'],true)
            ||(isset($parts['user'])||isset($parts['pass']))||isset($parts['port'])||isset($parts['fragment'])
            ||strlen($bytes)<1||strlen($bytes)>15728640||!preg_match('/^cos-[a-f0-9]{64}\.(jpg|png|webp|gif)$/D',$filename))
            throw new DomainException('Unsafe staged image upload target.');
        $fields=[];
        foreach($parameters as $row){
            $name=$row['name']??null;$value=$row['value']??null;
            if(!is_string($name)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$name)||strtolower($name)==='file'
                ||isset($fields[$name])||!is_string($value)||strlen($value)>16384)
                throw new DomainException('Invalid staged upload parameter.');
            $fields[$name]=$value;
        }
        if($fields===[]||count($fields)>30)throw new DomainException('Invalid staged upload parameter count.');
        $fields['file']=new \CURLStringFile($bytes,$filename,$mime);
        $curl=curl_init($url);
        if($curl===false)throw new DomainException('Cannot initialize Shopify staged upload.');
        try{
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            $response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($response===false||$status<200||$status>=300)throw new DomainException('Shopify staged image upload not confirmed.');
        }finally{curl_close($curl);}
    }
}
