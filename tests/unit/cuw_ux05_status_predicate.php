<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/symfony/src/Web/Operations/OperationStatus.php';
require dirname(__DIR__,2).'/symfony/src/Web/Operations/Read/OperationStatusPredicate.php';

use App\Web\Operations\OperationStatus as S;
use App\Web\Operations\Read\OperationStatusPredicate as Predicate;

foreach (['action','workflow','approval','import','agent','async'] as $source) {
    foreach (S::cases() as $status) {
        $predicate=Predicate::forSource($source,$status);
        if (!str_contains($predicate['sql'],':projected_status')
            || $predicate['value']!==($source==='workflow' && $status===S::Verified?S::Unverified->value:$status->value)) {
            throw new RuntimeException('Incorrect candidate binding: '.$source.'/'.$status->value);
        }
    }
}
if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) {
    echo "UX-05 status predicate bindings passed; SQL fixture skipped (PDO SQLite unavailable).\n";
    exit(0);
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('UTC_TIMESTAMP',static fn(int $precision):string=>'2026-11-27 12:00:00',1);
$db->exec('CREATE TABLE history (native_status TEXT,uncertain_steps INTEGER,action_status TEXT,expires_at TEXT)');
$fixtures=[
    ['action','PENDING_APPROVAL',0,null,null,[S::WaitingApproval]],
    ['action','COMPLETED',0,null,null,[S::Unverified]],
    ['action','UNKNOWN_NEW',0,null,null,[S::Blocked]],
    ['workflow','completed',0,null,null,[S::Verified,S::Unverified]],
    ['workflow','running',1,null,null,[S::Reconciliation]],
    ['workflow','ambiguous',1,null,null,[S::Unknown]],
    ['workflow','waiting',0,null,null,[S::Blocked]],
    ['approval','PENDING',0,'PENDING_APPROVAL','2026-11-27 12:01:00',[S::WaitingApproval]],
    ['approval','PENDING',0,'PENDING_APPROVAL','2026-11-27 11:59:00',[S::Blocked]],
    ['approval','PENDING',0,'QUEUED',null,[S::Blocked]],
    ['approval','APPROVED',0,null,null,[S::Unverified]],
    ['approval','REJECTED',0,null,null,[S::Cancelled]],
    ['import','applied',0,null,null,[S::Unverified]],
    ['import','blocked',0,null,null,[S::Blocked]],
    ['agent','INVALID_OUTPUT',0,null,null,[S::Failed]],
    ['agent','CREATED',0,null,null,[S::Queued]],
    ['async','FAILED',0,null,null,[S::Queued]],
    ['async','DEAD',0,null,null,[S::Failed]],
    ['async','RUNNING',0,null,null,[S::Running]],
];
foreach ($fixtures as [$source,$native,$uncertain,$action,$expires,$expected]) {
    $db->exec('DELETE FROM history');
    $insert=$db->prepare('INSERT INTO history VALUES (?,?,?,?)');
    $insert->execute([$native,$uncertain,$action,$expires]);
    foreach (S::cases() as $status) {
        $predicate=Predicate::forSource($source,$status);
        $query=$db->prepare('SELECT COUNT(*) FROM history WHERE '.$predicate['sql']);
        $query->execute(['projected_status'=>$predicate['value']]);
        if (((int)$query->fetchColumn()===1)!==in_array($status,$expected,true)) {
            throw new RuntimeException('Candidate predicate mismatch: '.$source.'/'.$native.'/'.$status->value);
        }
    }
}
echo "UX-05 status filter SQL candidate semantics passed.\n";
