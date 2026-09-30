<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
function v053(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__,2);
$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/DiagnosticMethodologyController.php');
v053(!str_contains($controller,'isManager(')&&!str_contains($controller,'isAdmin('),'Symfony Methodology API still aliases diagnostic permissions to application roles.');
v053(!str_contains($controller,'Domains\\'),'Symfony Methodology controller bypasses the Application boundary.');
$application=(string)file_get_contents($root.'/symfony/src/Application/Diagnostic/Methodology/DiagnosticMethodologyApplicationService.php');
foreach(['DiagnosticMethodologyAccess::EDIT','DiagnosticMethodologyAccess::PUBLISH','DiagnosticMethodologyAccess::VIEW'] as $permission)v053(str_contains($application,$permission),'Missing explicit application permission check: '.$permission);
$ui=(string)file_get_contents($root.'/symfony/assets/islands/diagnostic_methodology/base.js');
foreach(['data-choice-filter','data-add-group','data-condition-field','data-node','data-simulator-inputs','expected_findings'] as $contract)v053(str_contains($ui,$contract),'Human methodologist UI contract missing: '.$contract);
$overrides=json_decode((string)file_get_contents($root.'/resources/diagnostic/sales/0.2.0/studio-overrides.json'),true,512,JSON_THROW_ON_ERROR);
v053($overrides['metrics']['cost_per_lead']['type']==='currency','Cost per lead must be currency.');
v053($overrides['metrics']['pipeline_coverage']['type']==='ratio','Pipeline coverage must be a ratio.');
v053($overrides['metrics']['stage_conversion_rate']['type']==='percentage','Stage conversion must be a percentage.');
echo "Diagnostic V0.5.3 UX hardening contract passed.\n";
