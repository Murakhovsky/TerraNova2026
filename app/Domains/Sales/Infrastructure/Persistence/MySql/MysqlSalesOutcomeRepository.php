<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesOutcomeRepositoryInterface;
use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use PDO;
use RuntimeException;

final readonly class MysqlSalesOutcomeRepository implements SalesOutcomeRepositoryInterface
{
    public function __construct(private PDO $connection) {}
    public function record(RecordActionOutcomeCommand $command): string
    {
        $id=substr(hash('sha256',$command->organizationId.':'.$command->actionId.':'.$command->metric.':'.$command->measuredAt->format('U.u')),0,32);
        $statement=$this->connection->prepare('INSERT INTO cos_action_outcomes '
            . '(id,organization_id,action_id,metric,value,attribution_type,evidence,measured_at) '
            . 'SELECT :id,:organization_id,id,:metric,:value,:attribution_type,:evidence,:measured_at FROM cos_actions '
            . 'WHERE id=:action_id AND organization_id=:organization_scope');
        $statement->execute(['id'=>$id,'organization_id'=>$command->organizationId,'organization_scope'=>$command->organizationId,
            'action_id'=>$command->actionId,'metric'=>$command->metric,'value'=>json_encode($command->value,JSON_THROW_ON_ERROR),
            'attribution_type'=>$command->attribution->value,'evidence'=>json_encode($command->evidence,JSON_THROW_ON_ERROR),
            'measured_at'=>$command->measuredAt->format('Y-m-d H:i:s.u')]);
        if($statement->rowCount()!==1)throw new RuntimeException('Action does not belong to the current organization.');
        return $id;
    }
}
