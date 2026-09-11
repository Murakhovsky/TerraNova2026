<?php
declare(strict_types=1);
$root=dirname(__DIR__,2); require $root.'/vendor/autoload.php';
use Domains\Sales\Application\Service\SalesAdministrationHealthClassifier;
$classifier=new SalesAdministrationHealthClassifier();
$healthy=$classifier->classify([]); if(($healthy['status']??null)!=='HEALTHY') throw new RuntimeException('Zero health summary must be HEALTHY.'); foreach(['integrations','execution','approvals','agents'] as $s) if(($healthy['subsystems'][$s]??null)!=='HEALTHY') throw new RuntimeException('Healthy subsystem expected: '.$s);
$error=$classifier->classify(['integration_errors'=>2,'dead_jobs'=>1]); if(($error['status']??null)!=='ERROR'||($error['subsystems']['integrations']??null)!=='ERROR'||($error['subsystems']['execution']??null)!=='ERROR') throw new RuntimeException('Critical failures must classify as ERROR.'); if(count($error['issues']['critical']??[])!==2) throw new RuntimeException('Critical issues must be explicit.');
$degraded=$classifier->classify(['failed_jobs'=>1,'overdue_approvals'=>1,'agent_failures_24h'=>1,'integration_unknown'=>1]); if(($degraded['status']??null)!=='DEGRADED') throw new RuntimeException('Non-critical failures must be DEGRADED.'); foreach(['integrations','execution','approvals','agents'] as $s) if(($degraded['subsystems'][$s]??null)!=='DEGRADED') throw new RuntimeException('Expected DEGRADED subsystem: '.$s); if(count($degraded['issues']['warnings']??[])<4) throw new RuntimeException('Degraded health must expose warnings.');
echo "Sales V0.7.8 health classifier: OK\n";
