<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class) use ($root): void {
    if (!str_starts_with($class,'App\\')) return;
    $path=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if (is_file($path)) require $path;
});

use App\Infrastructure\Experience\Relations\SalesPropertyRelatedEntityRead;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Property\Contract\PropertyReferencePort;

final class Ux03FakeCase implements ClientCaseReadModelInterface {
    public function __construct(private string $org) {}
    public function filters(array $query): array {return [];}
    public function cases(array $filters): array {return [];}
    public function stats(): array {return [];}
    public function case(int $id): ?array {return $this->org==='org-a' && $id===11 ? ['id'=>11] : null;}
    public function inboundRequests(int $caseId): array {return [];}
    public function activities(int $caseId): array {return [];}
    public function propertyMatches(int $caseId): array {return [
        ['property_id'=>42,'match_status'=>'interested'],
        ['property_id'=>42,'match_status'=>'viewing'],
        ['property_id'=>43,'match_status'=>'interested'],
    ];}
    public function requestMatches(int $caseId): array {return [];}
    public function unlinkedInboundRequests(): array {return [];}
    public function inboundFilters(array $query): array {return [];}
    public function inboundInbox(array $filters): array {return [];}
    public function inboundInboxStats(): array {return [];}
    public function leadActivities(int $leadId): array {return [];}
    public function openCaseOptions(): array {return [];}
    public function managerOptions(): array {return [];}
}
final class Ux03FakeCaseFactory implements ClientCaseReadModelFactoryInterface {
    public function forOrganization(string $organizationId): ClientCaseReadModelInterface {
        return new Ux03FakeCase($organizationId);
    }
}
final class Ux03FakeProperty implements PropertyReferencePort {
    public function getPropertyReference(string $organizationId,string|int $reference): ?array {return null;}
    public function getInventorySnapshot(string $organizationId,string $inventoryId): ?array {return null;}
    public function findAvailableInventory(string $organizationId,array $criteria=[]): array {return [];}
    public function getPropertyPresentation(string $organizationId,string|int $reference): ?array {
        if ($organizationId!=='org-a' || (int)$reference!==42) return null;
        return ['property'=>['asset_id'=>'PROP-AAAAAAAAAAAAAAAAAAAA'],
                'listing'=>['title'=>'Verified property']];
    }
    public function searchPropertyReferences(string $organizationId,string $query,int $limit=100): array {return [];}
}
$authorization=new class implements \App\Security\SalesRequestEntityAuthorizationInterface {
    public bool $allow=true;
    public function entity(string $org,string $kind,int $id):bool{return $this->allow&&$org==='org-a'&&$kind==='deal'&&$id===11;}
};
$read=new SalesPropertyRelatedEntityRead(new Ux03FakeCaseFactory(),new Ux03FakeProperty(),$authorization);
$related=$read->related('org-a','sales.deal','11',10);
if(count($related)!==1 || $related[0]->type!=='property.asset'
    || $related[0]->id!=='PROP-AAAAAAAAAAAAAAAAAAAA'
    || $related[0]->label!=='Verified property') {
    throw new RuntimeException('Expected exactly one verified and deduplicated Property Match.');
}
if($read->related('org-b','sales.deal','11')!==[]
    || $read->related('org-a','sales.lead','11')!==[]
    || $read->related('org-a','sales.deal','12')!==[]
    || $read->related('org-a','sales.deal','invalid')!==[]) {
    throw new RuntimeException('Related entity read leaked foreign or unverified relation.');
}
$authorization->allow=false;
if($read->related('org-a','sales.deal','11')!==[])throw new RuntimeException('Same-tenant owner scope bypass.');
echo "UX03 Sales/Property related entities verified tenant boundary PASS.\n";
