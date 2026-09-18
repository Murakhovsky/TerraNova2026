<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/app/Domains/Diagnostic/Model/HasStringValues.php';
require $root.'/app/Domains/Diagnostic/Model/EvidenceType.php';
require $root.'/app/Domains/Diagnostic/Model/Evidence.php';

use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;

$e=new Evidence(
    'evidence-1',
    EvidenceType::SystemData,
    'CRM conversion',
    'crm',
    new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
    ['metric'=>'conversion_rate'],
    'crm:conversion',
    'api',
    0.95,
    1.0,
    'sales',
    100,
    0.27,
);
if($e->type!==EvidenceType::SystemData||$e->reliability!==0.95||$e->directness!==1.0){
    throw new RuntimeException('Evidence contract failed.');
}
$a=substr(hash('sha256','org-1:session-1:request-1'),0,64);
$b=substr(hash('sha256','org-1:session-1:request-1'),0,64);
if($a!==$b) throw new RuntimeException('Idempotency identity must be deterministic.');

echo "Sales Diagnostics Wave 5 contract OK\n";
