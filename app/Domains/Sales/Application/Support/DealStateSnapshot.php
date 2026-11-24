<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Support;

use DomainException;

final class DealStateSnapshot
{
    public static function fromNative(array $row,string $organizationId,int $id):array
    {
        if (($row['organization_id']??null)!==$organizationId || !in_array($row['id']??null,[$id,(string)$id],true)) {
            throw new DomainException('Native Deal identity mismatch.');
        }
        $state=[
            'deal_id'=>$id,'pipeline_id'=>(string)$row['pipeline_id'],'stage_id'=>(string)$row['stage_id'],
            'status'=>(string)$row['status'],'owner_id'=>$row['assigned_user_id']===null?null:(int)$row['assigned_user_id'],
            'deal_value'=>$row['deal_value']===null?null:(string)$row['deal_value'],'currency'=>(string)$row['currency'],
            'lost_reason_id'=>$row['lost_reason_id']===null?null:(string)$row['lost_reason_id'],
        ];
        if ($state['pipeline_id']==='' || $state['stage_id']==='' || !in_array($state['status'],['active','paused','closed','lost'],true)) throw new DomainException('Native Deal state is invalid.');
        $state['revision']=hash('sha256',json_encode([$organizationId,$state],JSON_THROW_ON_ERROR));
        return $state;
    }
}
