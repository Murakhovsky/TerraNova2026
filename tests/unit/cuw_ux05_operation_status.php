<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/symfony/src/Web/Operations/OperationStatus.php';

use App\Web\Operations\OperationStatus as S;

$cases = [
    ['action','QUEUED',false,false,S::Queued],
    ['action','PENDING_APPROVAL',false,false,S::WaitingApproval],
    ['action','COMPLETED',true,false,S::Unverified],
    ['action','REJECTED',false,false,S::Cancelled],
    ['agent','completed',true,false,S::Unverified],
    ['agent','waiting',false,false,S::Blocked],
    ['async','completed',true,false,S::Unverified],
    ['async','failed',false,true,S::Queued],
    ['async','failed',false,false,S::Failed],
    ['workflow','completed',false,false,S::Unverified],
    ['workflow','completed',true,false,S::Verified],
    ['workflow','ambiguous',false,false,S::Unknown],
    ['workflow','reconciliation_required',false,false,S::Reconciliation],
    ['workflow','future_unrecognized_state',true,false,S::Blocked],
    ['approval','pending',false,false,S::WaitingApproval],
    ['approval','approved',true,false,S::Unverified],
    ['approval','expired',false,false,S::Blocked],
    ['import','applied',true,false,S::Unverified],
    ['import','staged',false,false,S::Blocked],
];
foreach ($cases as [$source,$native,$verified,$retry,$expected]) {
    if (S::fromNative($source,$native,$verified,$retry) !== $expected) {
        throw new RuntimeException("Incorrect projection: $source/$native");
    }
}
foreach ([S::WaitingApproval,S::Blocked,S::Failed,S::Unverified,S::Unknown,S::Reconciliation] as $critical) {
    if (!$critical->needsAttention()) throw new RuntimeException('Hidden critical state '.$critical->value);
}
if (S::Verified->needsAttention()) throw new RuntimeException('Verified state marked as blocked.');
echo "UX-05 status evidence and attention contract passed.\n";
