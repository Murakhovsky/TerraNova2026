<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$messenger = (string) file_get_contents($root.'/symfony/config/packages/messenger.yaml');
$scheduler = (string) file_get_contents($root.'/symfony/src/Scheduler/CosScheduleProvider.php');
$compose = (string) file_get_contents($root.'/docker-compose.yml');

foreach (['engineering:', 'MESSENGER_ENGINEERING_TRANSPORT_DSN', 'ContinueEngineeringWorkflowsCommand'] as $needle) {
    if (!str_contains($messenger, $needle)) throw new RuntimeException('Engineering Messenger transport missing '.$needle);
}
if (!str_contains($scheduler, "'engineering'")) throw new RuntimeException('Engineering scheduler is not redispatched to dedicated transport.');
foreach (['engineering-worker:', 'messenger:consume","engineering', 'COS_ENGINEERING_GITHUB_TOKEN', 'networks: [frontend, backend]'] as $needle) {
    if (!str_contains($compose, $needle)) throw new RuntimeException('Dedicated engineering worker missing '.$needle);
}

echo "Engineering dedicated worker boundary passed.\n";
