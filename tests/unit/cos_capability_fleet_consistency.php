<?php
declare(strict_types=1);

use Kernel\Module\CapabilityFleetConsistencyGate;

require dirname(__DIR__,2).'/vendor/autoload.php';
$gate=new CapabilityFleetConsistencyGate();
$sha=str_repeat('a',40);
$digest=str_repeat('b',64);
$manifest=[
    'format'=>'cos-capability-fleet-manifest-v1',
    'release_sha'=>$sha,
    'expected_instances'=>[
        ['id'=>'web-01','role'=>'web'],
        ['id'=>'worker-01','role'=>'worker'],
    ],
];
$contract=[
    'id'=>'sales.create_task','owner_domain'=>'sales',
    'version'=>'1.0.0','contract_digest'=>$digest,
    'binding_family'=>'action','witness'=>'ACTION_HANDLER_REGISTERED','issue'=>null,
];
$make=static fn(string $id,string $role):array=>[
    'format'=>'cos-capability-runtime-instance-witness-v1',
    'scope'=>'CURRENT_SYMFONY_CONTAINER_ONLY',
    'instance_id'=>$id,'instance_role'=>$role,'deployment_sha'=>$sha,
    'instance_provenance_verified'=>false,
    'worker_admission_mode'=>'WORKER_DISABLED_FAIL_CLOSED',
    'worker_execution_certified'=>false,
    'tenant_policies_checked'=>false,'approval_receipts_checked'=>false,
    'native_business_outcomes_checked'=>false,
    'typed_contracts'=>1,'action_registration_issues'=>0,
    'capabilities'=>[$contract],
];
$web=$make('web-01','web');
$worker=$make('worker-01','worker');
$assert=static function(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException($why);
};
$deny=static function(array $records,string $reason)use($gate,$manifest,$assert):void {
    $result=$gate->inspect($manifest,$records);
    $assert($result['consistent']===false
        && $result['status']==='FAIL_CLOSED'
        && $result['production_certified']===false
        && count($result['errors'])>0,$reason);
};
$pass=$gate->inspect($manifest,[$worker,$web]);
$assert($pass['consistent']===true
    && $pass['status']==='CONSISTENT_UNCERTIFIED'
    && $pass['production_certified']===false
    && $pass['deployment_provenance_verified']===false
    && $pass['operator_sign_off_required']===true
    && $pass['expected_instance_count']===2
    && $pass['received_instance_count']===2,
    'Matching replica snapshots are consistent but must remain uncertified.');
$deny([$web],'Missing worker replica was not rejected.');
$deny([$web,$web],'Duplicate instance was accepted.');
$drift=$worker;
$drift['capabilities'][0]['contract_digest']=str_repeat('c',64);
$deny([$web,$drift],'Cross-replica schema drift was accepted.');
$bad=$worker;
$bad['worker_admission_mode']='WORKER_ADMISSION_REGISTERED_NOT_CERTIFIED';
$deny([$web,$bad],'Unexpected worker activation was accepted.');
$bad=$worker;
$bad['deployment_sha']=str_repeat('d',40);
$deny([$web,$bad],'Release SHA drift was accepted.');
$bad=$worker;
$bad['instance_role']='web';
$deny([$web,$bad],'Wrong replica role was accepted.');
$bad=$worker;
$bad['capabilities'][0]['witness']='UNVERIFIED';
$deny([$web,$bad],'Missing registered Action handler was accepted.');
$bad=$worker;
$bad['action_registration_issues']=1;
$deny([$web,$bad],'Action registration issues were ignored.');
$bad=$worker;
$bad['instance_provenance_verified']=true;
$deny([$web,$bad],'Fake provenance certification was accepted.');
$bad=$worker;
$bad['approval_receipts_checked']=true;
$deny([$web,$bad],'Fake Approval receipt certification was accepted.');
$bad=$worker;
$bad['typed_contracts']=2;
$deny([$web,$bad],'Wrong typed contract count was accepted.');
$bad=$worker;
$bad['capabilities'][0]['id']='fraudulent.other_contract';
$deny([$web,$bad],'Different capability IDs were accepted.');
$bad=$worker;
$bad['instance_id']='unknown-replica';
$deny([$web,$bad],'Unlisted replica was accepted.');
$bad=$manifest;
$bad['expected_instances'][1]['id']='web-01';
try {
    $gate->inspect($bad,[$web,$worker]);
    throw new RuntimeException('Duplicate fleet manifest IDs were accepted.');
} catch(DomainException) {}
$bad=$manifest;
$bad['expected_instances'][1]['role']='web';
try {
    $gate->inspect($bad,[$web,$worker]);
    throw new RuntimeException('Workerless manifest was accepted.');
} catch(DomainException) {}
echo "COS Capability fleet drift, SHA, roster, Action registrations and fail-closed evidence PASS.\n";
