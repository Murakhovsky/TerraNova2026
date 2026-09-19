<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mustContain = static function (string $path, array $needles, string $label) use ($root): void {
    $content = (string) file_get_contents($root . '/' . $path);
    foreach ($needles as $needle) if (!str_contains($content, $needle)) throw new RuntimeException("Sales V0.7.2 {$label} missing: {$needle}");
};

$mustContain('app/migrations/20260910_000031_sales_v072_pipeline_administration.sql', ["ENUM('DRAFT','ACTIVE','DISABLED','ARCHIVED')",'initial_stage_id','configuration_version','sales_lost_reasons','lost_reason_id','source_stage.is_terminal = 1','source_stage.is_terminal = 0'], 'migration');
$mustContain('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlPipelineRepository.php', ['initial_stage_id','s.status = "ACTIVE"','isValidLostReason','lostReasons'], 'runtime repository');
$mustContain('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php', ['s.id = p.initial_stage_id','s.status = "ACTIVE"'], 'creation boundary');
$clientCaseRepository = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php');
if (str_contains($clientCaseRepository, 'INSERT INTO sales_deal_stage_history')) {
    throw new RuntimeException('Sales creation boundary must not write the event-owned history projection directly.');
}
$mustContain('app/Domains/Sales/Application/Service/SalesInboundService.php', ['ClientCaseCreated::create'], 'creation event boundary');
$mustContain('app/Domains/Sales/Application/UseCase/ChangeDealStage.php', ['An active lost reason is required when moving a Deal to LOST.','defaultLostReasonId','isValidLostReason',"['lost_reason_id']"], 'lost lifecycle');
$mustContain('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlDealRepository.php', ['lost_reason_id','lost_reason_note'], 'deal persistence');
$dealRepository = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlDealRepository.php');
if (str_contains($dealRepository, 'INSERT INTO sales_deal_stage_history')) {
    throw new RuntimeException('Sales V0.7.2 compatibility gate must not restore direct history writes retired by Sales V0.8.3.');
}
$mustContain('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesPipelineAdministration.php', ['CONFIGURATION_CONFLICT','Pipeline code is immutable','Stage code is immutable','Stage has active deals','assertPipelineValid','cos_configuration_revisions',"'TRANSITION'","'LOST_REASON'"], 'administration');
$mustContain('symfony/config/routes.yaml', ['cos_web_sales_admin_pipelines_page:', 'cos_web_sales_admin_pipeline_page:'], 'server-rendered Symfony routes');
$mustContain('symfony/config/routes.yaml', [
    '/api/v1/sales/admin/pipelines',
    '/stages/reorder',
    '/transitions',
    '/lost-reasons',
    '/api/v1/sales/opportunities/{id}/stage',
], 'canonical Symfony routes');
$mustContain('app/Bootstrap/SalesServices.php', ['MysqlSalesPipelineAdministration',"'salesPipelineAdministration'"], 'DI');

require_once $root . '/app/Domains/Sales/Model/PipelineStageDefinition.php';
require_once $root . '/app/Domains/Sales/Model/PipelineDefinition.php';
use Domains\Sales\Model\PipelineDefinition;
use Domains\Sales\Model\PipelineStageDefinition;
$initial = new PipelineStageDefinition('s1','NEW','New',10,false,false,false,5);
$won = new PipelineStageDefinition('s2','WON','Won',20,true,true,false,100);
$lost = new PipelineStageDefinition('s3','LOST','Lost',30,true,false,true,0);
$pipeline = new PipelineDefinition('p1','org1','default','Default',[$initial,$won,$lost],'s1');
if ($pipeline->initialStage()->id !== 's1') throw new RuntimeException('Explicit initial stage lookup failed.');
try { new PipelineDefinition('p2','org1','invalid','Invalid',[$initial,$won,$lost],'missing'); throw new RuntimeException('Missing initial stage accepted.'); } catch (DomainException) {}
echo "Sales V0.7.2 pipeline administration contract passed.\n";
