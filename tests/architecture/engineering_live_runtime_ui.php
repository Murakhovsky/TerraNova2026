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
    'Тривалість workflow',
    'активна робота агентів',
    'data-engineering-live-duration-start',
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
    'relativeTimeLabel(',
] as $needle) {
    if (!str_contains($page, $needle)) {
        throw new RuntimeException('Engineering runtime duration presentation missing '.$needle);
    }
}

echo "Engineering live runtime UI contract passed.\n";
