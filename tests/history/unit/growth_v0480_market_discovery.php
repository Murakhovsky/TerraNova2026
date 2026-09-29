<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthMarketSourceInterface;
use Domains\Growth\Application\DTO\GrowthMarketDiscoveryBatch;
use Domains\Growth\Application\Service\GrowthMarketSourceRegistry;
use Domains\Growth\Domain\GrowthMarketUniverse;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\OpportunityType;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV0480(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$universe=new GrowthMarketUniverse(
    'GMU-TEST',OrganizationId::fromString('org-1'),'SaaS 30-200',
    GrowthMarketUniverse::SOURCE_CREDENTIALED_JSON,'https://provider.example/v1/companies',
    GrowthMarketUniverse::AUTH_BEARER,'env://MARKET_TOKEN',null,'GICP-TEST',2,75,
    OpportunityType::CustomerAcquisition,GrowthMode::Acquire,'sales',true,
);
expectGrowthV0480($universe->enabled(),'Universe must preserve enabled state.');
expectGrowthV0480($universe->minIcpFit===75,'Universe must preserve ICP threshold.');

$source=new class implements GrowthMarketSourceInterface{
    public function type():string{return GrowthMarketUniverse::SOURCE_CREDENTIALED_JSON;}
    public function discover(GrowthMarketUniverse $universe,?string $cursor,int $limit):GrowthMarketDiscoveryBatch{
        return new GrowthMarketDiscoveryBatch([],null);
    }
};
$registry=new GrowthMarketSourceRegistry([$source]);
expectGrowthV0480($registry->types()===[GrowthMarketUniverse::SOURCE_CREDENTIALED_JSON],'Market registry source type mismatch.');
expectGrowthV0480($registry->get(GrowthMarketUniverse::SOURCE_CREDENTIALED_JSON)===$source,'Market registry lookup failed.');

$blocked=false;
try{
    new GrowthMarketUniverse(
        'GMU-BAD',OrganizationId::fromString('org-1'),'Bad endpoint',
        GrowthMarketUniverse::SOURCE_CREDENTIALED_JSON,'http://127.0.0.1/internal',
        GrowthMarketUniverse::AUTH_BEARER,'env://TOKEN',null,'GICP-TEST',1,70,
        OpportunityType::CustomerAcquisition,GrowthMode::Acquire,'sales',true,
    );
}catch(InvalidArgumentException){$blocked=true;}
expectGrowthV0480($blocked,'Market Universe must reject non-HTTPS endpoint.');

$duplicate=false;
try{new GrowthMarketSourceRegistry([$source,$source]);}catch(InvalidArgumentException){$duplicate=true;}
expectGrowthV0480($duplicate,'Market source registry must reject duplicate source types.');

echo "Growth V0.48 Market Discovery domain contracts passed.\n";
