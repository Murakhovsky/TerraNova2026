<?php
declare(strict_types=1);
namespace App\Security;

use DomainException;
use Domains\Sales\Application\Service\SalesEntityScopeService;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;

/** Request-bound authority for shared Workspace projections outside Sales controllers. */
final readonly class SalesRequestScope
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

    public function entity(string $org,string $kind,int $id):bool
    {
        try {$this->scope->requireEntity($org,$this->actor($org),$kind,$id);return true;}
        catch (DomainException) {return false;}
    }
}
