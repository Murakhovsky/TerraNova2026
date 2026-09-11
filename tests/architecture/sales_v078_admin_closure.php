<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$required=['app/Domains/Sales/Application/Contract/SalesAdministrationReadModelInterface.php','app/Domains/Sales/Application/Service/SalesAdministrationHealthClassifier.php','app/Infrastructure/Platform/ReadModel/MySql/MysqlSalesAdministrationReadModel.php','app/Bootstrap/SalesAdministrationServices.php','app/Interfaces/Api/Controller/SalesAdminHealthController.php','app/Interfaces/Web/Controller/SalesAdminHealthController.php','app/Interfaces/Web/Routing/SalesAdministrationRoutes.php','app/Interfaces/Web/View/sales_admin/health.phtml','app/migrations/20260911_000036_sales_v078_admin_closure.sql'];
foreach($required as $path) if(!is_file($root.'/'.$path)) throw new RuntimeException('Missing V0.7.8 artifact: '.$path);
$readModel=(string)file_get_contents($root.'/app/Infrastructure/Platform/ReadModel/MySql/MysqlSalesAdministrationReadModel.php');
foreach(['cos_integrations','cos_jobs','cos_actions','cos_approvals','cos_agent_runs','cos_crm_inbox','cos_audit_log','cos_configuration_revisions','cos_operational_metrics'] as $table) if(!str_contains($readModel,$table)) throw new RuntimeException('V0.7.8 read model is missing canonical source: '.$table);
foreach(['credentials_reference','before_payload','after_payload','SELECT *'] as $forbidden) if(str_contains($readModel,$forbidden)) throw new RuntimeException('V0.7.8 control center exposes sensitive data: '.$forbidden);
if(preg_match('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\b/i',$readModel)) throw new RuntimeException('V0.7.8 administration read model must remain read-only.');
$migration=(string)file_get_contents($root.'/app/migrations/20260911_000036_sales_v078_admin_closure.sql');
foreach(["'INTEGRATION_ROUTE'","'ACTIVATE'",'sales.admin.audit.view'] as $needle) if(!str_contains($migration,$needle)) throw new RuntimeException('V0.7.8 closure migration missing: '.$needle);
$routes=(string)file_get_contents($root.'/app/Interfaces/Web/Routing/SalesAdministrationRoutes.php');
foreach(['/sales/admin/health','/api/sales/admin/health'] as $route) if(!str_contains($routes,$route)) throw new RuntimeException('V0.7.8 route missing: '.$route);
$api=(string)file_get_contents($root.'/app/Interfaces/Api/Controller/SalesAdminHealthController.php'); $web=(string)file_get_contents($root.'/app/Interfaces/Web/Controller/SalesAdminHealthController.php');
if(!str_contains($api,'SalesCapability::AdminAuditView')||!str_contains($web,'SalesCapability::AdminAuditView')) throw new RuntimeException('V0.7.8 access must use sales.admin.audit.view.');
$module=(string)file_get_contents($root.'/app/Interfaces/Web/Module.php'); if(!str_contains($module,'SalesAdministrationRoutes::register')) throw new RuntimeException('V0.7.8 routes are not registered.');
$services=(string)file_get_contents($root.'/app/config/services_kernel.php'); if(!str_contains($services,'SalesAdministrationServices.php')) throw new RuntimeException('V0.7.8 services are not registered.');
$operations=(string)file_get_contents($root.'/app/Infrastructure/Platform/ReadModel/MySql/MysqlOperationsReadModel.php'); $start=strpos($operations,"'integrations' =>"); $end=strpos($operations,"'actions' =>",$start===false?0:$start); if($start===false||$end===false) throw new RuntimeException('Cannot verify operations integration projection.'); $projection=substr($operations,$start,$end-$start);
foreach(['credentials_reference',' configuration, ',' type, name, status'] as $forbidden) if(str_contains($projection,$forbidden)) throw new RuntimeException('Operations integration projection exposes obsolete/sensitive field: '.$forbidden);
foreach(['integration_key','configuration_version','health_status'] as $field) if(!str_contains($projection,$field)) throw new RuntimeException('Operations integration projection missing V0.7.7 field: '.$field);
echo "Sales V0.7.8 audit/health/admin closure architecture: OK\n";
