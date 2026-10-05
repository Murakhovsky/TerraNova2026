<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manager = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$product = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringProductRequirementsStageExecutor.php');
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');

if (!str_contains($manager, 'EngineeringProductRequirementsStageExecutor')) {
    throw new RuntimeException('Manager compatibility stage must delegate to Product Requirements.');
}
foreach (['ArtifactType::FEATURE_SPEC','AgentRole::PRODUCT_REQUIREMENTS','createFromProductRequirements','applyProductSpecification'] as $needle) {
    if (!str_contains($product, $needle)) throw new RuntimeException('Product Requirements stage missing '.$needle);
}
if (!str_contains($orchestrator, 'productStage->execute')) throw new RuntimeException('EngineeringOrchestrator does not delegate Product Requirements stage.');
if (str_contains($manager, 'ArtifactType::FEATURE_SPEC')) throw new RuntimeException('Manager stage still owns Product artifacts.');

echo "Engineering Manager/Product responsibility split passed.\n";
