<?php
declare(strict_types=1);
namespace App\Security;

use DomainException;
use Domains\Sales\Application\Service\SalesEntityScopeService;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;

/** Request-bound authority for shared Workspace projections outside Sales controllers. */
final readonly class SalesRequestScope implements SalesRequestEntityAuthorizationInterface
{
    public function __construct(private TenantContextProviderInterface $tenants,private ActiveModuleResolver $modules,
        private SalesEntityScopeService $scope) {}

    public function actor(string $org):int
    {
        $tenant=$this->tenants->current();
        $raw=$tenant?->userId()->value()??'';
        if ($tenant===null||$tenant->organizationId()->value()!==$org||!$tenant->isManager()
            ||!$tenant->allows(TenantPermissions::ACCESS)||!ctype_digit($raw)||(int)$raw<1
            ||(string)(int)$raw!==$raw||!$this->modules->isEnabled($org,'sales')) throw new DomainException('Sales access denied.');
        $actor=(int)$raw;$this->scope->resolve($org,$actor);return $actor;
    }

    public function searchOwner(string $org):?int
    {
        $actor=$this->actor($org);
        try {return $this->scope->resolve($org,$actor,'tenant');}
        catch (DomainException) {return $actor;}
    }

    public function ownerFor(string $org,mixed $owner):?int
    {
        $actor=$this->actor($org);
        if ($owner===null||$owner==='') return $this->searchOwner($org);
        if ((!is_int($owner)&&!is_string($owner))||!ctype_digit((string)$owner)||(int)$owner<1||(string)(int)$owner!==(string)$owner)
            throw new DomainException('Invalid Sales owner scope.');
        return $this->scope->resolve($org,$actor,'owner',(int)$owner);
    }

    /** Only active, authorized owner options; no email, capability or team metadata. */
    public function ownerOptions(string $org,array $users):array
    {
        $actor=$this->actor($org);$options=[];
        foreach ($users as $user) {
            if (!is_array($user)||(int)($user['id']??0)<1) continue;
            $id=(int)$user['id'];
            try {$this->scope->resolve($org,$actor,'owner',$id);}
            catch (DomainException) {continue;}
            $options[]=['id'=>$id,'full_name'=>(string)($user['full_name']??''),'status'=>(string)($user['status']??'active'),
                'organization_role'=>(string)($user['organization_role']??$user['role']??'manager')];
        }
        return $options;
    }

    public function entity(string $org,string $kind,int $id):bool
    {
        try {$this->scope->requireEntity($org,$this->actor($org),$kind,$id);return true;}
        catch (DomainException) {return false;}
    }
}
