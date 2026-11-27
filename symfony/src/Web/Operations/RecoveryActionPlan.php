<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionIntent;
use App\Web\Experience\Action\UIActionPlacement;
use Kernel\Tenant\Model\TenantPermissions;

/** Pure presentation of known canonical evidence; performs no dispatch or authorization. */
final class RecoveryActionPlan
{
    public static function build(string $run, int $revision, string $actor, array $candidates, array $requests): array
    {
        if ($revision < 1 || $run === '' || $actor === '') return [];
        $options = [];
        $activeSteps=[];
        foreach ($requests as $r) {
            if ($revision===$r['runRevision'] && in_array($r['status'],['REQUESTED','APPROVED'],true)
                && isset($candidates[$r['step']]) && $candidates[$r['step']]['revision']===$r['stepRevision']) $activeSteps[$r['step']]=true;
        }
        foreach ($candidates as $step=>$candidate) {
            if ((int)$candidate['revision']>0 && !$candidate['active'] && !isset($activeSteps[$step])) {
                foreach ($candidate['modes'] as $resolution) {
                    if (!in_array($resolution, ['verified_complete','reset_read_only'], true)) continue;
                    $options[] = self::option($run,$revision,(string)$step,(int)$candidate['revision'],'request',$resolution,(string)$step);
                }
            }
        }
        foreach ($requests as $r) {
            $candidate = $candidates[$r['step']] ?? null;
            if ($candidate === null || $revision !== $r['runRevision'] || $candidate['revision'] !== $r['stepRevision']
                || !in_array($r['resolution'],$candidate['modes'],true)
                || !preg_match('/^[a-f0-9]{32}$/D',(string)$r['id'])
                || $r['requestedBy'] === ''
                || ($r['resolution']==='verified_complete' && (!is_string($r['receipt'] ?? null) || trim($r['receipt'])===''))
                || ($r['resolution']==='reset_read_only' && ($r['receipt'] ?? null)!==null)) continue;
            if ($r['status'] === 'REQUESTED' && $r['requestedBy'] !== $actor) {
                $options[] = self::option($run,$revision,$r['step'],$r['stepRevision'],'approve',$r['resolution'],$r['id'],$r);
            } elseif ($r['status'] === 'APPROVED' && $r['approvedBy'] !== '' && $r['approvedBy'] !== $r['requestedBy']) {
                $options[] = self::option($run,$revision,$r['step'],$r['stepRevision'],'apply',$r['resolution'],$r['id'],$r);
            }
        }
        return $options;
    }

    private static function option(string $run, int $revision, string $step, int $stepRevision, string $operation, string $resolution, string $resource, array $petition = []): array
    {
        $label = match ($operation) {
            'request' => $resolution === 'verified_complete' ? 'Запит на підтвердження квитанції' : 'Запит на безпечний read-only reset',
            'approve' => 'Погодити recovery як другий менеджер',
            'apply' => 'Застосувати незалежно погоджене recovery',
        };
        // A stale rendered action ID cannot authorize a newer run/step version.
        $id = 'operations.recovery.'.$operation.'.r'.hash('sha256',json_encode([$run,$revision,$step,$stepRevision,$resolution,$resource],JSON_THROW_ON_ERROR));
        $action = new UIAction(
            id:$id,label:$label,intent:$operation === 'approve' ? UIActionIntent::Approve : UIActionIntent::Execute,
            permission:TenantPermissions::MANAGE,confirmation:'Підтвердіть recovery. Ця операція не повторює зовнішню дію; застосування може відновити канонічний Workflow.',
            command:'operations.recovery.'.$operation,dangerLevel:1,
            placements:[UIActionPlacement::WORKSPACE_SECONDARY],resourceId:$resource,
        );
        return ['action'=>$action,'operation'=>$operation,'resolution'=>$resolution,'resource'=>$resource,
            'runRevision'=>$revision,'stepRevision'=>$stepRevision,'step'=>$step,
            'receipt'=>$petition['receipt'] ?? null,'reason'=>$petition['reason'] ?? null];
    }
}
