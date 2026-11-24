<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($root):void {
    if(!str_starts_with($class,'App\\'))return;
    $f=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($f)) require $f;
});

use App\Infrastructure\Experience\Business\TenantBusinessEntitySummaryRead;
use Domains\Property\Contract\PropertyReferencePort;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;

final class Ux03SummarySales implements SalesWorkspaceReadModelInterface {
    public function dashboard(string $organizationId,?int $ownerId=null):array{return [];}
    public function leads(string $organizationId,array $filters=[]):array{return [];}
    public function lead(string $organizationId,int $leadId):?array{return null;}
    public function deals(string $organizationId,array $filters=[]):array{return [];}
    public function deal(string $organizationId,int $dealId):?array{
        return $organizationId==='tenant-a' && $dealId===4
            ? ['id'=>4,'status'=>'OPEN','stage_name'=>'Negotiation','owner_name'=>'Alex'] : null;
    }
    public function timeline(string $organizationId,int $dealId,int $limit=100):array{return [];}
    public function pipelines(string $organizationId):array{return [];}
    public function today(string $organizationId,int $ownerId):array{return [];}
    public function metrics(string $organizationId,int $days=30):array{return [];}
}
final class Ux03SummaryProperty implements PropertyReferencePort {
    public function getPropertyReference(string $organizationId,string|int $reference):?array{return null;}
    public function getInventorySnapshot(string $organizationId,string $inventoryId):?array{return null;}
    public function findAvailableInventory(string $organizationId,array $criteria=[]):array{return [];}
    public function getPropertyPresentation(string $organizationId,string|int $reference):?array{
        if($organizationId!=='tenant-a'||$reference!=='PROP-AAAAAAAAAAAAAAAAAAAA') return null;
        return ['property'=>['asset_id'=>$reference,'type_code'=>'house','formatted_address'=>'Lviv'],
          'inventory'=>['status'=>'available']];
    }
    public function searchPropertyReferences(string $organizationId,string $query,int $limit=100):array{return [];}
}
$authorization=new class implements \App\Security\SalesRequestEntityAuthorizationInterface {
    public bool $allow=true;
    public function entity(string $org,string $kind,int $id):bool{return $this->allow&&$org==='tenant-a'&&$kind==='deal'&&$id===4;}
};
$reader=new TenantBusinessEntitySummaryRead(new Ux03SummarySales(),new Ux03SummaryProperty(),$authorization);
$deal=$reader->facts('tenant-a','sales.deal','4');
$asset=$reader->facts('tenant-a','property.asset','PROP-AAAAAAAAAAAAAAAAAAAA');
if(count($deal)!==3||count($asset)!==3||$asset[0]['value']!=='house')throw new RuntimeException('Verified business summary missing');
foreach([
 ['tenant-b','sales.deal','4'],['tenant-b','property.asset','PROP-AAAAAAAAAAAAAAAAAAAA'],
 ['tenant-a','sales.deal','0'],['tenant-a','property.asset','PROP-not-real'],
] as [$org,$type,$id]){
    if($reader->facts($org,$type,$id)!==[])throw new RuntimeException('Summary leaked unverified record');
}
$authorization->allow=false;
if($reader->facts('tenant-a','sales.deal','4')!==[])throw new RuntimeException('Same-tenant owner scope bypass.');
echo "UX03 typed Sales/Property summary tenant boundary PASS.\n";
