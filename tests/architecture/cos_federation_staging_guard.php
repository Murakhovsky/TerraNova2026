<?php
declare(strict_types=1);

/** Non-mutating Docker fleet and isolated Federation staging safety contract. */
$root = dirname(__DIR__, 2);
$script = (string)file_get_contents($root.'/bin/ops/cos_docker_fleet_audit.py');
$guard = (string)file_get_contents($root.'/deploy/staging/guard.sh');
$compose = (string)file_get_contents($root.'/deploy/staging/federation-compose.yml');
$nginx = (string)file_get_contents($root.'/deploy/staging/nginx.conf');

$assert = static function(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
foreach (['--fixture','--inspect-logs','cross_project_persistence_volume_collision',
    'cross_project_active_writer_endpoint_collision','unhealthy_execution_consumer',
    'multiple_compose_projects_require_authority_review','no_side_effects',
    'staging_ready=False'] as $needle) {
    $assert(str_contains($script, $needle), 'Fleet audit missing: '.$needle);
}
foreach (['COS_FED_STAGING_OPERATOR_APPROVAL','APPROVE_ISOLATED_FEDERATION_STAGING',
    'AVAILABLE_MIB < 2048','DISK_MIB < 12288','FLEET_EXIT != 0',
    'git rev-parse HEAD','--inspect-logs','cos-federation-staging',
    'COS_FED_STAGING_SHA','docker compose -p cos-federation-staging'] as $needle) {
    $assert(str_contains($guard, $needle), 'Staging guard missing: '.$needle);
}
foreach (['name: cos-federation-staging','fed_staging_mysql','fed_staging_redis',
    'internal: true','127.0.0.1:18081:80','COS_AGENT_LLM_MODE: fixture',
    'COS_SALES_AUTOMATION_SCHEDULER_ENABLED: "0"',
    'TELEGRAM_BOT_TOKEN: ""','COS_FED_STAGING_DB_PASSWORD',
    'COS_FED_STAGING_SHA','mysql:','redis:','php:','nginx:'] as $needle) {
    $assert(str_contains($compose, $needle), 'Isolated compose missing: '.$needle);
}
$assert(str_contains($nginx, 'fastcgi_pass staging_php'), 'Missing dedicated staging Nginx');
foreach (['network_mode: host','/var/run/docker.sock','--privileged','network_mode: service:cos',
    'cos_mysql_data','cos_redis_data','company-os.shop','cos:schema:migrate',
    'doctrine:migrations:migrate','docker compose down','--remove-orphans',
    'docker volume rm'] as $forbidden) {
    $assert(!str_contains($compose."\n".$guard, $forbidden),
        'Dangerous staging operation or production coupling: '.$forbidden);
}

$mk = static function(string $project, string $service, string $name,
                      string $state='running', string $volume=''): array {
    return [
        'Name'=>'/'.$name, 'RestartCount'=>$state==='restarting'?5:0,
        'Config'=>['Labels'=>['com.docker.compose.project'=>$project,
                            'com.docker.compose.service'=>$service],
                   'Env'=>['DATABASE_URL=mysql://cos:secret@mysql/cos',
                           'MESSENGER_TRANSPORT_DSN=redis://redis:6379/cos_async']],
        'State'=>['Running'=>$state==='running','Restarting'=>$state==='restarting',
                  'Status'=>$state,'ExitCode'=>$state==='restarting'?1:0,'OOMKilled'=>false],
        'Mounts'=>$volume===''?[]:[['Name'=>$volume,'Type'=>'volume']],
        'NetworkSettings'=>['Networks'=>[$project.'_backend'=>[]]]
    ];
};
$run = static function(array $fixture) use ($root): array {
    $path=tempnam(sys_get_temp_dir(),'cos-staging-fixture-');
    if ($path===false) throw new RuntimeException('Unable to create fixture');
    try {
        file_put_contents($path,json_encode($fixture,JSON_THROW_ON_ERROR));
        $command='python3 '.escapeshellarg($root.'/bin/ops/cos_docker_fleet_audit.py').
            ' --fixture '.escapeshellarg($path);
        $output=[]; $exit=0;
        exec($command, $output, $exit);
        $result=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
        $result['_exit']=$exit;
        return $result;
    } finally {
        unlink($path);
    }
};
$healthy=$run([$mk('cos','worker','cos-worker-1')]);
$assert($healthy['status']==='PASS' && $healthy['_exit']===0,
    'Healthy single project should pass fixture inventory');
$restart=$run([$mk('cos','kernel-worker','cos-kernel-worker-1','restarting')]);
$assert($restart['status']==='BLOCKED' && $restart['_exit']!==0,
    'Restarting kernel worker must block staging');
$separate=$run([$mk('cos','worker','cos-worker-1'),
               $mk('cos-symfony','worker','cos-symfony-worker-1')]);
$assert($separate['status']==='REVIEW' && $separate['_exit']!==0,
    'Two active execution projects must be explicitly reviewed');
$collide=$run([$mk('cos','mysql','cos-mysql-1','running','shared-db'),
              $mk('cos-symfony','mysql','cos-symfony-mysql-1','running','shared-db')]);
$assert($collide['status']==='BLOCKED' &&
    in_array('cross_project_persistence_volume_collision',$collide['blockers'],true),
    'Cross-project DB volume collision must fail closed');
foreach ([$healthy,$restart,$separate,$collide] as $result) {
    $assert(($result['no_side_effects']??false) === true &&
        ($result['staging_ready']??true) === false,
        'Fleet inspection cannot authorize staging execution');
}
echo "COS Federation staging isolation and Docker fleet guard PASS; live fleet pending.\n";
