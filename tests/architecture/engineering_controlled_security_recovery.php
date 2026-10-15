<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root.$path);
$service = $read('/symfony/src/Engineering/Application/Service/EngineeringControlledSecurityRecoveryService.php');
$worker = $read('/symfony/src/Application/Engineering/Command/RunEngineeringFeatureCommandHandler.php');
$architect = $read('/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$specialist = $read('/symfony/src/Engineering/Application/Service/EngineeringSpecialistStageExecutor.php');
$coordinator = $read('/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');
foreach (['function authorize(', 'function executeOnce(', 'function releaseToDeveloper(', 'DUPLICATE_IGNORED', 'function lastPermit(', 'reviewArchitectureOnly(', 'lock->synchronized(', 'baseline_agent_runs', "'no_auto_developer'"] as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('Recovery service contract missing '.$needle);
}
foreach (["trigger === 'security-recovery'", 'securityRecovery->executeOnce('] as $needle) {
    if (!str_contains($worker, $needle)) throw new RuntimeException('One-shot immediate worker dispatch missing '.$needle);
}
if (!str_contains($architect, 'bool $targetedSecurityRecovery = false')
    || !str_contains($architect, 'TARGETED_RECOVERY_ROUND_LIMIT')
    || !str_contains($specialist, 'function reviewArchitectureOnly(')
    || !str_contains($specialist, 'if ($reviewOnly) return null')) {
    throw new RuntimeException('Targeted Architect / Security limits are missing.');
}
foreach (['beginTargetedSecurityRecovery(', 'parkTargetedSecurityRecovery(', 'releaseAfterTargetedSecurityReview('] as $needle) {
    if (!str_contains($coordinator, $needle)) throw new RuntimeException('Explicit recovery transition missing '.$needle);
}
echo "Controlled Security recovery contract passed.\n";
