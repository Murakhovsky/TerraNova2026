<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthJsonSignalReaderInterface;
use Domains\Growth\Application\Contract\GrowthJsonSignalSourceRepositoryInterface;
use Domains\Growth\Application\DTO\ExternalJsonSignalEntry;
use Domains\Growth\Application\DTO\SignalCollectionRequest;
use Domains\Growth\Domain\GrowthJsonSignalSource;
use Domains\Growth\Infrastructure\Collector\CredentialedJsonSignalCollector;
use Domains\Growth\Infrastructure\Feed\CredentialedJsonSignalParser;
use Infrastructure\Platform\Integration\EnvironmentCredentialVault;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Model\Credential;

function expectGrowthV0280(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$org=OrganizationId::fromString('org-1');

$bearer=new GrowthJsonSignalSource(
    'GSJS-1',$org,'Provider API','https://api.example.test/signals',
    GrowthJsonSignalSource::AUTH_BEARER,'env://GROWTH_JSON_TEST_CREDENTIAL',null,
    'account','account-1','provider_signal',0.87,true,
);
expectGrowthV0280($bearer->enabled(),'Credentialed JSON source must preserve enabled state.');
$bearer->disable();
expectGrowthV0280(!$bearer->enabled(),'Credentialed JSON source disable failed.');
$bearer->enable();

$apiKey=new GrowthJsonSignalSource(
    'GSJS-2',$org,'API key provider','https://api.example.test/events',
    GrowthJsonSignalSource::AUTH_API_KEY_HEADER,'env://GROWTH_JSON_TEST_CREDENTIAL','X-API-Key',
    'account','account-2','provider_signal',0.75,true,
);
expectGrowthV0280($apiKey->apiKeyHeader==='X-API-Key','API-key source lost safe header name.');

try{
    new GrowthJsonSignalSource(
        'BAD',$org,'Bad URL','http://api.example.test/signals',
        GrowthJsonSignalSource::AUTH_BEARER,'env://GROWTH_JSON_TEST_CREDENTIAL',null,
        'account','account-1','provider_signal',0.8,true,
    );
    throw new RuntimeException('Credentialed JSON source must reject non-HTTPS URL.');
}catch(InvalidArgumentException){}

try{
    new GrowthJsonSignalSource(
        'BAD2',$org,'Bad header','https://api.example.test/signals',
        GrowthJsonSignalSource::AUTH_API_KEY_HEADER,'env://GROWTH_JSON_TEST_CREDENTIAL','Authorization',
        'account','account-1','provider_signal',0.8,true,
    );
    throw new RuntimeException('Credentialed JSON source must reject unsafe API-key header.');
}catch(InvalidArgumentException){}

putenv('GROWTH_JSON_TEST_CREDENTIAL={"token":"fixture-token","api_key":"fixture-key"}');
$vault=new EnvironmentCredentialVault();
$resolved=$vault->resolve(new Credential(
    'cred-1',$org,'GSJS-1','bearer','env://GROWTH_JSON_TEST_CREDENTIAL',['growth.signals.read'],
));
expectGrowthV0280(($resolved['token']??null)==='fixture-token','Environment CredentialVault did not resolve bearer token.');
expectGrowthV0280(($resolved['api_key']??null)==='fixture-key','Environment CredentialVault did not resolve API key.');
try{
    $vault->resolve(new Credential('cred-2',$org,'GSJS-1','bearer','file:///tmp/secret',['growth.signals.read']));
    throw new RuntimeException('Environment CredentialVault must reject non-env reference.');
}catch(InvalidArgumentException){}
putenv('GROWTH_JSON_TEST_CREDENTIAL');

$parser=new CredentialedJsonSignalParser();
$json=json_encode([
    'items'=>[
        [
            'id'=>'event-2',
            'occurred_at'=>'2026-09-24T11:00:00+00:00',
            'source_reference'=>'https://provider.example/events/2',
            'facts'=>['kind'=>'funding','amount'=>1200000,'verified'=>true],
        ],
        [
            'id'=>'event-1',
            'occurred_at'=>'2026-09-24T10:00:00+00:00',
            'source_reference'=>'https://provider.example/events/1',
            'facts'=>['kind'=>'leadership_change','role'=>'COO'],
        ],
        [
            'id'=>'broken',
            'occurred_at'=>'not-a-date',
            'source_reference'=>'https://provider.example/events/broken',
            'facts'=>['kind'=>'broken'],
        ],
    ],
],JSON_THROW_ON_ERROR);
$entries=$parser->parse($json,10);
expectGrowthV0280(count($entries)===2,'Credentialed JSON parser must skip invalid items.');
expectGrowthV0280($entries[0]->externalId==='event-2','Credentialed JSON parser must order newest first.');
expectGrowthV0280(($entries[0]->facts['verified']??null)===true,'Credentialed JSON parser must preserve scalar facts.');

$repo=new class implements GrowthJsonSignalSourceRepositoryInterface {
    public array $rows=[[
        'organization_id'=>'org-1','source_id'=>'GSJS-1','name'=>'Provider API','url'=>'https://api.example.test/signals',
        'auth_mode'=>'bearer','credential_reference'=>'env://GROWTH_JSON_TEST_CREDENTIAL','api_key_header'=>null,
        'subject_type'=>'account','subject_id'=>'account-1','signal_type'=>'provider_signal','confidence'=>0.87,'enabled'=>true,
    ]];
    public function create(GrowthJsonSignalSource $source,int $actorId):void{}
    public function lock(string $organizationId,string $sourceId):GrowthJsonSignalSource{throw new RuntimeException('unused');}
    public function update(GrowthJsonSignalSource $source,int $actorId):void{}
    public function view(string $organizationId,string $sourceId):?array{return $this->rows[0]??null;}
    public function listAll(string $organizationId,int $limit=200):array{return $this->rows;}
    public function listEnabled(string $organizationId,int $limit=200):array{return $this->rows;}
};

$reader=new class implements GrowthJsonSignalReaderInterface {
    public function read(GrowthJsonSignalSource $source,int $limit):array
    {
        return [
            new ExternalJsonSignalEntry(
                'provider-event-1','https://provider.example/events/provider-event-1',
                ['kind'=>'funding','stage'=>'series_a'],new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
            ),
        ];
    }
};

$collector=new CredentialedJsonSignalCollector($repo,$reader);
expectGrowthV0280($collector->name()==='credentialed_json','Credentialed JSON collector canonical name mismatch.');
$batch=$collector->collect(new SignalCollectionRequest('org-1',null,10));
expectGrowthV0280(count($batch->items)===1,'Credentialed JSON collector must map provider entries.');
$item=$batch->items[0];
expectGrowthV0280($item->subjectType==='account'&&$item->subjectId==='account-1','Credentialed JSON collector lost configured subject.');
expectGrowthV0280($item->signalType==='provider_signal','Credentialed JSON collector lost signal type.');
expectGrowthV0280(abs($item->confidence-0.87)<0.0001,'Credentialed JSON collector lost confidence.');
expectGrowthV0280(($item->facts['provider']??null)==='credentialed_json','Credentialed JSON provenance is missing.');
expectGrowthV0280(($item->facts['json_source_id']??null)==='GSJS-1','Credentialed JSON source identity is missing.');
expectGrowthV0280(str_starts_with($item->externalKey,'credentialed_json:'),'Credentialed JSON external key namespace is wrong.');

try{
    $collector->collect(new SignalCollectionRequest('org-1','cursor-not-supported',10));
    throw new RuntimeException('Credentialed JSON collector must reject cursor use.');
}catch(InvalidArgumentException){}

echo "Growth V0.28 Credentialed JSON Signal Collector contracts passed.\n";
