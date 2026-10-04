<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReportBuilder.php');
$qa = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$finalize = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringFinalizeService.php');

foreach (['total_tokens','total_cost','duration_seconds','reviewCycles','qaCycles','execution_cycles','tests_skipped','recommendation','required_checks','time_to_pr_seconds','time_to_ready_seconds','first_pass_success',"'gate_status'", '$architectureContent[\'status\']', "'repository_revision'","'primary_owner_domain'","'conditions'"] as $needle) {
    if (!str_contains($builder, $needle)) throw new RuntimeException('Engineering final report missing '.$needle);
}
if (!str_contains($qa, 'ArtifactType::FINAL_REPORT')) throw new RuntimeException('READY_FOR_HUMAN_APPROVAL report is not created by QA.');
if (!str_contains($finalize, 'reports->build')) throw new RuntimeException('DONE report does not reuse canonical report builder.');

echo "Engineering final report passed.\n";
