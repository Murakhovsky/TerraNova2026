<?php
declare(strict_types=1);

use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel;

$root=dirname(__DIR__,2);
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=array_map('trim',explode('=',$line,2));if(getenv($k)===false)putenv($k.'='.trim($v,"\"'"));}
require $root.'/vendor/autoload.php';
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$required=['sales_pipelines','sales_pipeline_stages','sales_pipeline_transitions','sales_communications','cos_action_outcomes','sales_metric_snapshots'];
$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('.implode(',',array_fill(0,count($required),'?')).')');$q->execute($required);
if((int)$q->fetchColumn()!==count($required))throw new RuntimeException('Sales runtime tables are incomplete.');
$read=new MysqlSalesWorkspaceReadModel($pdo);
$pipelines=$read->pipelines('default');
if(!$pipelines||count($pipelines[0]['stages']??[])<8)throw new RuntimeException('Default configurable pipeline was not provisioned.');
if($read->deals('tenant-does-not-exist')!==[])throw new RuntimeException('Sales workspace leaked cross-tenant deals.');
$dashboard=$read->dashboard('default');
foreach(['kpis','today','at_risk','new_leads','metrics'] as $key)if(!array_key_exists($key,$dashboard))throw new RuntimeException('Dashboard missing '.$key);
echo "Sales workspace MySQL schema, projection and tenant boundary passed.\n";
