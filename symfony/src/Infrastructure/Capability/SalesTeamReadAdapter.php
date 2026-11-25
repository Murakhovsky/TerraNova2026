<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Admin\SalesAdminAuthorization;
use App\Application\Sales\TeamSnapshot;
use DomainException;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesTeamReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesTeamAdministrationInterface $teams,private SalesAdminAuthorization $authorization) {}
    public function binding():string { return 'query:sales.team.read'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $org=$actor->organizationId()->value();$user=$actor->userId()->value();
        if (!ctype_digit($user)||(int)$user<1||(string)(int)$user!==$user||!$this->authorization->allows($org,(int)$user,SalesAdminAuthorization::TEAMS)) throw new DomainException('Sales team administration permission required.');
        $id=$input['team_id']??null;
        if (count($input)!==1||!is_string($id)||preg_match('/^[A-Za-z0-9_-]{8,40}$/D',$id)!==1) throw new DomainException('Invalid team identity.');
        $row=$this->teams->team($org,$id);
        if ($row!==null&&($row['id']??null)!==$id) throw new DomainException('Invalid native team observation.');
        return ['found'=>$row!==null,'team'=>$row===null?null:TeamSnapshot::fromRow($row)];
    }
}
