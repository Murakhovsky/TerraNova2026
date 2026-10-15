<?php
declare(strict_types=1);

/**
 * P0 execution-authority route proof: source-contract assertions only.
 * The real SQL smoke is executed separately by Federation MySQL and Runtime.
 * This gate must never promote production tenant/fleet acceptance.
 */
$root=dirname(__DIR__,2);
$load=static function (string $path) use ($root): string {
    $content=file_get_contents($root.'/'.$path);
    if ($content===false) throw new RuntimeException('Missing execution source: '.$path);
    // Ignore comments, so a marker cannot pass solely via stale documentation.
    $withoutComments='';
    foreach (token_get_all($content) as $token) {
        if (is_array($token) && in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)) continue;
        $withoutComments.=is_array($token)?$token[1]:$token;
    }
    return $withoutComments;
};
$mustContain=static function (string $source,array $markers,string $name): void {
    foreach ($markers as $marker) {
        if (!str_contains($source,$marker)) {
            throw new RuntimeException('Execution route proof missing '.$name.': '.$marker);
        }
    }
};
$noTechnicalWrites=static function (string $source,string $name): void {
    // Both raw SQL and DBAL table-level mutation methods; quoted identifiers
    // and whitespace must not make direct Run/Step writes invisible.
    $sql='~\\b(?:INSERT\\s+(?:IGNORE\\s+)?INTO|REPLACE\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+[`"]?cos_(?:workflow|federation)_(?:runs|steps)[`"]?(?=\\s|[,(;]|$)~i';
    $dbal='~->\\s*(?:insert|update|delete)\\s*\\(\\s*[\'"]cos_(?:workflow|federation)_(?:runs|steps)[\'"]~i';
    if (preg_match($sql,$source) || preg_match($dbal,$source)) {
        throw new RuntimeException('Direct technical Run/Step writer bypass: '.$name);
    }
};
$gateway=$load('symfony/src/Engineering/Infrastructure/Persistence/Doctrine/EngineeringCanonicalTechnicalExecutionGateway.php');
$engineeringStore=$load('symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php');
$bridge=$load('symfony/src/Persistence/Federation/FederationCanonicalWorkflowBridge.php');
$controller=$load('symfony/src/Web/Federation/FederationRunController.php');
$platformStore=$load('symfony/src/Persistence/Workflow/DoctrineWorkflowExecutionStore.php');
$services=$load('symfony/config/services.yaml');

$mustContain($gateway, [
    'WorkflowTechnicalExternalStoreInterface','->beginGuardedExternal(',
    '->completeGuardedExternal(','->quarantineGuardedExternal(',
    'cos_engineering_technical_rollouts','cos_engineering_technical_links',
], 'Engineering delegated technical store');
$mustContain($engineeringStore,['->begin(', '->complete(', '->quarantine('],
    'Engineering persisted AgentRun hooks');
$mustContain($bridge,[
    'WorkflowExecutionStoreInterface','->create(', '->claim(', '->checkpoint(',
    'source_plan_id=:plan','cos_federation_runs','FOR UPDATE',
], 'Federation canonical bridge');
$mustContain($controller,[
    '->canonical->start(', '->canonical->advance(', 'legacy_run_execution_retired',
], 'Federation entrypoint');
$mustContain($platformStore,[
    'WorkflowTechnicalExternalStoreInterface','beginGuardedExternal(',
    'completeGuardedExternal(','quarantineGuardedExternal(',
], 'shared Platform authority');
$mustContain($services,[
    'Platform\\Workflow\\Contract\\WorkflowTechnicalExternalStoreInterface',
    'Platform\\Workflow\\Contract\\WorkflowExecutionStoreInterface',
], 'DI canonical ports');
$noTechnicalWrites($gateway,'Engineering gateway');
$noTechnicalWrites($bridge,'Federation bridge');
if (str_contains($controller,'FederationSequentialOrchestrator')
    || str_contains($controller,'->orchestrator->start(')
    || str_contains($controller,'->orchestrator->advance(')) {
    throw new RuntimeException('Legacy Federation executor has returned to new Run entrypoint.');
}
$registry=json_decode((string)file_get_contents($root.'/resources/architecture/cos-execution-duplication-registry.json'),true,512,JSON_THROW_ON_ERROR);
$byId=array_flip(array_column($registry['entries'],'id'));
foreach (['EX-002','EX-003'] as $id) {
    $item=$registry['entries'][$byId[$id]??-1]??[];
    if (($item['migration_status']??null)!=='IN_PROGRESS'
        || ($item['runtime_consumers_verified']??null)!==false
        || ($item['implementation_evidence']['acceptance']??null)!=='CI_MYSQL_PASS__FLEET_ACCEPTANCE_PENDING') {
        throw new RuntimeException('Technical path must remain bounded/unaccepted: '.$id);
    }
}
$case=json_decode((string)file_get_contents($root.'/resources/architecture/cos-execution-parity-cases.json'),true,512,JSON_THROW_ON_ERROR);
$cp20=array_values(array_filter($case['tests'],static fn(array $t):bool=>$t['id']==='CP-20'));
if (count($cp20)!==1 || $cp20[0]['target_status']!=='IN_PROGRESS') {
    throw new RuntimeException('CP-20 cannot be promoted by narrow SQL acceptance.');
}
$runtime=$load('.github/workflows/runtime.yml');
foreach ([
    'workflow_engineering_technical_shadow_mysql.php',
    'federation_canonical_goal_e2e_mysql.php',
    'cos_execution_authority_paths.php',
] as $anchor) {
    if (!str_contains($runtime,$anchor)) {
        throw new RuntimeException('Runtime acceptance runner missing: '.$anchor);
    }
}
echo "COS P0 execution route proof passed: Federation and Engineering delegate; fleet acceptance PENDING.\n";
