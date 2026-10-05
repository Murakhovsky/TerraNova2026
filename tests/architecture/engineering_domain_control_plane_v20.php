<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    'policy' => 'symfony/src/Engineering/Application/Policy/EngineeringPolicyEngine.php',
    'agent_caps' => 'symfony/src/Engineering/Application/Policy/AgentCapabilityRegistry.php',
    'runtime_caps' => 'symfony/src/Engineering/Application/Policy/RuntimeCapabilityRegistry.php',
    'shared_kernel' => 'symfony/src/Engineering/Application/Policy/EngineeringSharedKernelRegistry.php',
    'human_gate' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainHumanGateService.php',
    'budget' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainBudgetGuard.php',
    'compressor' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainContextCompressor.php',
    'repo_index' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringRepositoryContextIndex.php',
    'documentation' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainDocumentationService.php',
    'artifact_graph' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringArtifactDependencyGraph.php',
    'planner' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainPlanner.php',
    'scheduler' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainFeatureScheduler.php',
    'release' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainReleaseService.php',
    'developer' => 'symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php',
    'runtime' => 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainRuntimeService.php',
    'gateway' => 'symfony/src/Engineering/Application/Repository/EngineeringRepositoryGatewayInterface.php',
    'migration' => 'symfony/migrations/Version20261005193000.php',
];

$src = [];
foreach ($files as $key => $path) {
    if (!is_file($root.'/'.$path)) throw new RuntimeException('Engineering control plane missing '.$path);
    $src[$key] = (string) file_get_contents($root.'/'.$path);
}

foreach (['featureStart','contractChangeRequiresHuman','migrationRequiresHuman','domainReleaseRequiresHuman'] as $needle) {
    if (!str_contains($src['policy'], $needle)) throw new RuntimeException('Engineering Policy Engine missing '.$needle);
}
foreach (['repository_write','commit','pull_request','merge','production'] as $needle) {
    if (!str_contains($src['agent_caps'], $needle)) throw new RuntimeException('Agent Capability Registry missing '.$needle);
}
foreach (['GitHub','CI','Repository','FeatureRuntime','DomainScheduler','ProductionExecution'] as $needle) {
    if (!str_contains($src['runtime_caps'], $needle)) throw new RuntimeException('Runtime Capability Registry missing '.$needle);
}

foreach (['Money','Currency','Identifier','Clock','TenantId','UserId','DomainEvent','OrganizationId'] as $needle) {
    if (!str_contains($src['shared_kernel'], $needle)) throw new RuntimeException('Shared Kernel registry missing '.$needle);
}
foreach (['createHumanDecision','answerHumanDecision','HUMAN_APPROVAL','resume_status'] as $needle) {
    if (!str_contains($src['human_gate'].$src['runtime'], $needle)) throw new RuntimeException('Human Control Plane missing '.$needle);
}

foreach (['context_budget','token_budget','cost_budget','max_feature_retries','max_domain_integration_cycles'] as $needle) {
    if (!str_contains($src['migration'].$src['runtime'].$src['budget'], $needle)) {
        throw new RuntimeException('Domain safety budget missing '.$needle);
    }
}
foreach (['DOMAIN_RESOURCE_BUDGET','FEATURE_RETRY_','DOMAIN_INTEGRATION_BUDGET'] as $needle) {
    if (!str_contains($src['budget'].$src['scheduler'].$src['release'], $needle)) {
        throw new RuntimeException('Budget human extension missing '.$needle);
    }
}

foreach (['full_artifact','canonical_summary','hash','relevant_sections'] as $needle) {
    if (!str_contains($src['compressor'], $needle)) throw new RuntimeException('Context compression contract missing '.$needle);
}
foreach (['classes','interfaces','services','entities','routes','migrations','tests','modules','dependencies'] as $needle) {
    if (!str_contains($src['repo_index'], "'".$needle."'")) throw new RuntimeException('Repository index missing '.$needle);
}
if (!str_contains($src['gateway'], 'repositoryTree')) throw new RuntimeException('Repository gateway does not expose tree index.');

foreach ([
    'DOMAIN_DOCUMENTATION_PUBLIC',
    'DOMAIN_DOCUMENTATION_INTEGRATOR',
    'DOMAIN_DOCUMENTATION_DEVELOPER',
    'DOMAIN_DOCUMENTATION_TRANSLATIONS',
] as $needle) {
    if (!str_contains($src['documentation'].$src['release'], $needle)) {
        throw new RuntimeException('Domain documentation layer missing '.$needle);
    }
}
foreach (['PUBLIC_BUSINESS','INTEGRATOR','DEVELOPER','canonical_locale','future_locales_supported'] as $needle) {
    if (!str_contains($src['documentation'], $needle)) throw new RuntimeException('Documentation audience/locale contract missing '.$needle);
}

foreach (['commits','migrations','new_events','deprecated_contracts','feature_flags','known_limitations','qa_result','security_result','rollback_plan'] as $needle) {
    if (!str_contains($src['release'], "'".$needle."'")) throw new RuntimeException('Release Manifest V2 contract missing '.$needle);
}

foreach (['replaceArtifactDependencies','SUPERSEDES','RELEASE_EVIDENCE','TRANSLATION_SOURCE'] as $needle) {
    if (!str_contains($src['artifact_graph'], $needle)) throw new RuntimeException('Artifact Dependency Graph missing '.$needle);
}
foreach (['BREAKING_CONTRACT_','RISKY_MIGRATION','EXTERNAL_PRODUCTION_INTEGRATION'] as $needle) {
    if (!str_contains($src['planner'], $needle)) throw new RuntimeException('Architecture policy human gate missing '.$needle);
}
foreach (['repository_write','commit','pull_request','merge','production'] as $needle) {
    if (!str_contains($src['developer'], $needle)) throw new RuntimeException('Developer capability enforcement missing '.$needle);
}

echo "Engineering Runtime V2 control plane passed.\n";
