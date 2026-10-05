<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $full = $root.'/'.$path;
    if (!is_file($full)) {
        throw new RuntimeException('Missing Engineering live runtime UI file: '.$path);
    }
    return (string) file_get_contents($full);
};

$routes = $read('symfony/config/routes.yaml');
$api = $read('symfony/src/Http/Api/V1/Controller/EngineeringController.php');
$template = $read('symfony/templates/experience/engineering/feature.html.twig');
$controller = $read('symfony/assets/controllers/engineering_live_controller.js');
$uiActions = $read('symfony/src/Engineering/Application/Service/EngineeringUiActionResolver.php');
$page = $read('symfony/src/Web/Engineering/EngineeringFeatureController.php');
$security = $read('symfony/config/packages/security.yaml');
$authenticator = $read('symfony/src/Security/SessionAuthenticator.php');
$managerStage = $read('symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');

foreach ([
    'cos_engineering_feature_live:',
    '/api/engineering/features/{id}/live',
    'EngineeringController::live',
] as $needle) {
    if (!str_contains($routes, $needle)) {
        throw new RuntimeException('Engineering live route contract missing '.$needle);
    }
}

foreach ([
    'public function live(',
    "'timeline_count'",
    "'agent_runs' => \$runs",
    "'timeline' => \$timeline",
] as $needle) {
    if (!str_contains($api, $needle)) {
        throw new RuntimeException('Engineering compact live API missing '.$needle);
    }
}

foreach ([
    'data-controller="engineering-live"',
    'data-engineering-live-target="terminal"',
    'data-engineering-live-target="pollStatus"',
    'data-engineering-live-target="heartbeat"',
    'Тривалість процесу',
    'робота агентів:',
    'data-engineering-live-duration-start',
    'data-engineering-live-timestamp',
    'LLM ще не запускався',
    'Токени',
    'Стан runtime',
    'ЩЕ НЕ РОЗПОЧАТО',
    'Менеджер розробки',
] as $needle) {
    if (!str_contains($template, $needle)) {
        throw new RuntimeException('Engineering live UI missing '.$needle);
    }
}

foreach ([
    'window.setInterval',
    'fetch(this.urlValue',
    'renderTimeline(',
    'relativeTime(',
    'formatDuration(',
    'parseTimestamp(',
    'formatTimestamp(',
    'formatStaticTimestamps(',
    "mysqlUtc[1] + 'T'",
    ".padEnd(3, '0')",
    "health === 'STALLED' ? 'STALLED' : status",
    'localizeStatus(',
    'localizeRole(',
    'localizeEventType(',
    "'сигнал: '",
    'без нових подій',
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('Engineering live Stimulus controller missing '.$needle);
    }
}

if (!str_contains($uiActions, "\$activeExecution = \$hasWorkflow && \$workflowStatus === 'RUNNING'")) {
    throw new RuntimeException('Engineering UI still lacks active-execution action suppression.');
}
if (!str_contains($uiActions, "&& !\$activeExecution")) {
    throw new RuntimeException('Engineering continue action is still exposed during active execution.');
}

foreach ([
    "'agent_runtime_label'",
    "'run_durations'",
    "'heartbeat_label'",
    "'display_status'",
    "\$resolvedHealth === 'STALLED' ? 'STALLED' : \$workflowStatus",
    "\$runtimeHealth === 'STALLED' => 'STALLED'",
    'relativeTimeLabel(',
] as $needle) {
    if (!str_contains($page, $needle)) {
        throw new RuntimeException('Engineering runtime duration presentation missing '.$needle);
    }
}


foreach ([
    "api/engineering",
    "path: '^/api/engineering(?:/|$)'",
] as $needle) {
    if (!str_contains($security, $needle)) {
        throw new RuntimeException('Engineering live API is outside the authenticated firewall: '.$needle);
    }
}
if (!str_contains($authenticator, "str_starts_with(\$path,'/api/engineering')")) {
    throw new RuntimeException('SessionAuthenticator does not authenticate Engineering live API requests.');
}
if (!str_contains($managerStage, 'AgentRun start failed after repository discovery:')) {
    throw new RuntimeException('Engineering Manager does not surface pre-AgentRun start failures.');
}

echo "Engineering live runtime UI contract passed.\n";
