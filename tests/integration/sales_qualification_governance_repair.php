<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\MySql\Migration\SqlStatementSplitter;

$root=dirname(__DIR__,2);
$autoload=(string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');
$migrationRoot=(string)(getenv('COS_TEST_MIGRATIONS')?:$root.'/app/migrations');
foreach(is_file($root.'/.env')?(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]):[] as $line){
    $line=trim($line);
    if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$key,$value]=array_map('trim',explode('=',$line,2));
    if(getenv($key)===false)putenv($key.'='.trim($value,"\"'"));
}
require $autoload;

$pdo=new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST')?:'mysql',
        (int)(getenv('DB_PORT')?:3306),
        getenv('DB_DATABASE')?:'cos',
    ),
    getenv('DB_USERNAME')?:'cos',
    getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);

$organizationId='default';
$fixtures=[
    'restore'=>[
        'pipeline'=>'gov-restore',
        'source'=>'gov-restore-new',
        'target'=>'gov-restore-qualified',
        'code'=>'gov-restore',
    ],
    'omit'=>[
        'pipeline'=>'gov-omit',
        'source'=>'gov-omit-new',
        'target'=>'gov-omit-qualified',
        'code'=>'gov-omit',
    ],
    'default'=>[
        'pipeline'=>'gov-default',
        'source'=>'gov-default-new',
        'target'=>'gov-default-qualified',
        'code'=>'gov-default',
    ],
];

$cleanup=static function()use($pdo,$organizationId,$fixtures):void{
    $ids=array_column($fixtures,'pipeline');
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $revision=$pdo->prepare(
        'DELETE FROM cos_configuration_revisions WHERE organization_id=? AND domain_name="sales" '
        .'AND configuration_type="TRANSITION" AND entity_id IN ('.$placeholders.')'
    );
    $revision->execute(array_merge([$organizationId],$ids));
    $pipeline=$pdo->prepare(
        'DELETE FROM sales_pipelines WHERE organization_id=? AND id IN ('.$placeholders.')'
    );
    $pipeline->execute(array_merge([$organizationId],$ids));
};

$cleanup();

try{
    $insertPipeline=$pdo->prepare(
        'INSERT INTO sales_pipelines '
        .'(id,organization_id,code,name,is_default,initial_stage_id,status,configuration_version) '
        .'VALUES (:id,:organization_id,:code,:name,0,NULL,"ACTIVE",1)'
    );
    $insertStage=$pdo->prepare(
        'INSERT INTO sales_pipeline_stages '
        .'(id,organization_id,pipeline_id,code,name,sort_order,is_terminal,is_won,is_lost,probability_default,status,configuration_version) '
        .'VALUES (:id,:organization_id,:pipeline_id,:code,:name,:sort_order,0,0,0,:probability,"ACTIVE",1)'
    );
    $insertTransition=$pdo->prepare(
        'INSERT INTO sales_pipeline_transitions '
        .'(id,organization_id,pipeline_id,from_stage_id,to_stage_id,requires_approval,conditions) '
        .'VALUES (:id,:organization_id,:pipeline_id,:from_stage_id,:to_stage_id,0,JSON_ARRAY())'
    );
    $insertRevision=$pdo->prepare(
        'INSERT INTO cos_configuration_revisions '
        .'(organization_id,domain_name,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at) '
        .'VALUES (:organization_id,"sales","TRANSITION",:entity_id,1,"UPDATE","USER","governance-remediation-test",'
        .'"CI governance remediation fixture",NULL,:after_payload,NOW(6))'
    );

    foreach($fixtures as $name=>$fixture){
        $insertPipeline->execute([
            'id'=>$fixture['pipeline'],
            'organization_id'=>$organizationId,
            'code'=>$fixture['code'],
            'name'=>'Governance '.ucfirst($name),
        ]);
        $insertStage->execute([
            'id'=>$fixture['source'],
            'organization_id'=>$organizationId,
            'pipeline_id'=>$fixture['pipeline'],
            'code'=>'NEW',
            'name'=>'New',
            'sort_order'=>10,
            'probability'=>5,
        ]);
        $insertStage->execute([
            'id'=>$fixture['target'],
            'organization_id'=>$organizationId,
            'pipeline_id'=>$fixture['pipeline'],
            'code'=>'QUALIFIED',
            'name'=>'Qualified',
            'sort_order'=>30,
            'probability'=>25,
        ]);
    }

    foreach(['restore','omit'] as $name){
        $fixture=$fixtures[$name];
        $insertTransition->execute([
            'id'=>'edge-'.$name,
            'organization_id'=>$organizationId,
            'pipeline_id'=>$fixture['pipeline'],
            'from_stage_id'=>$fixture['source'],
            'to_stage_id'=>$fixture['target'],
        ]);
    }

    $restore=$fixtures['restore'];
    $restoreSnapshot=[[
        'id'=>'historic-restore-edge',
        'organization_id'=>$organizationId,
        'pipeline_id'=>$restore['pipeline'],
        'from_stage_id'=>$restore['source'],
        'to_stage_id'=>$restore['target'],
        'requires_approval'=>1,
        'conditions'=>[['field'=>'budget','operator'=>'gte','value'=>100000]],
    ]];
    $insertRevision->execute([
        'organization_id'=>$organizationId,
        'entity_id'=>$restore['pipeline'],
        'after_payload'=>json_encode($restoreSnapshot,JSON_THROW_ON_ERROR),
    ]);
    $insertRevision->execute([
        'organization_id'=>$organizationId,
        'entity_id'=>$fixtures['omit']['pipeline'],
        'after_payload'=>'[]',
    ]);

    $sql=(string)file_get_contents($migrationRoot.'/20261003_000123_sales_lead_qualification_governance_repair.sql');
    foreach((new SqlStatementSplitter())->split($sql) as $statement){
        $query=$pdo->prepare($statement);
        $query->execute();
        do{
            if($query->columnCount()>0)$query->fetchAll(PDO::FETCH_NUM);
        }while($query->nextRowset());
        $query->closeCursor();
    }

    $read=$pdo->prepare(
        'SELECT requires_approval,conditions FROM sales_pipeline_transitions '
        .'WHERE organization_id=:organization_id AND pipeline_id=:pipeline_id '
        .'AND from_stage_id=:from_stage_id AND to_stage_id=:to_stage_id LIMIT 1'
    );

    $read->execute([
        'organization_id'=>$organizationId,
        'pipeline_id'=>$restore['pipeline'],
        'from_stage_id'=>$restore['source'],
        'to_stage_id'=>$restore['target'],
    ]);
    $restored=$read->fetch();
    $conditions=is_array($restored)?json_decode((string)$restored['conditions'],true):null;
    if(!is_array($restored)||(int)$restored['requires_approval']!==1||($conditions[0]['field']??null)!=='budget'){
        throw new RuntimeException('Governance repair did not restore tenant approval/conditions.');
    }

    $omit=$fixtures['omit'];
    $read->execute([
        'organization_id'=>$organizationId,
        'pipeline_id'=>$omit['pipeline'],
        'from_stage_id'=>$omit['source'],
        'to_stage_id'=>$omit['target'],
    ]);
    if($read->fetch()!==false){
        throw new RuntimeException('Governance repair recreated an edge omitted by tenant revision history.');
    }

    $default=$fixtures['default'];
    $read->execute([
        'organization_id'=>$organizationId,
        'pipeline_id'=>$default['pipeline'],
        'from_stage_id'=>$default['source'],
        'to_stage_id'=>$default['target'],
    ]);
    $canonical=$read->fetch();
    if(!is_array($canonical)||(int)$canonical['requires_approval']!==0||json_decode((string)$canonical['conditions'],true)!==[]){
        throw new RuntimeException('Governance repair did not provision the canonical default edge for an unconfigured pipeline.');
    }

    echo "Sales qualification governance remediation passed.\n";
}finally{
    $cleanup();
}
