<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\Marketing\Infrastructure\Meta\CurlMetaHttpTransport;
use Domains\Marketing\Infrastructure\Meta\MetaGraphReadAdapter;

$transport=new CurlMetaHttpTransport();
foreach(['http://graph.facebook.com/v27.0/me','https://evil.test/x','https://graph.facebook.com:444/x','https://user@graph.facebook.com/x'] as $url){
    try{$transport->get($url,'fixture');}catch(RuntimeException){continue;}
    throw new RuntimeException('Unapproved Meta transport host accepted: '.$url);
}
// Domain boundary has no external write contract or network write method.
$provider=new ReflectionClass(MetaGraphReadAdapter::class);
foreach(['createPausedCampaign','activate','pause','updateBudget','setDeliveryStatus'] as $forbidden){
    if($provider->hasMethod($forbidden))throw new RuntimeException('Unsafe provider write method exposed: '.$forbidden);
}
echo "Marketing Meta read security boundary PASS\n";

