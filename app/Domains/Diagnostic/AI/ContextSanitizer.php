<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;
final class ContextSanitizer
{
    private const SENSITIVE=['password','secret','token','api_key','authorization','email','phone'];
    public function sanitize(array $context):array{$walk=function(array $data)use(&$walk):array{$out=[];foreach($data as $key=>$value){$normalized=strtolower((string)$key);if(in_array($normalized,self::SENSITIVE,true)||str_contains($normalized,'password')||str_contains($normalized,'secret')||str_contains($normalized,'token')){$out[$key]='[REDACTED]';continue;}$out[$key]=is_array($value)?$walk($value):$value;}return $out;};return $walk($context);}
}
