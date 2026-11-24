<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\ManageSalesTeamCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesTeamManageAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:sales.team.manage'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $user=$actor->userId()->value();
        if (!ctype_digit($user)||(int)$user<1||(string)(int)$user!==$user) throw new DomainException('Invalid team administrator.');
        $result=$this->commands->dispatch(new ManageSalesTeamCommand($actor->organizationId(),(int)$user,$input,$key,$correlationId));
        if (!is_array($result)||!is_string($result['team']['team_id']??null)||!is_bool($result['replayed']??null)
            || (isset($input['team_id'])&&$result['team']['team_id']!==$input['team_id'])) throw new DomainException('Invalid team receipt.');
        return $result;
    }
}
