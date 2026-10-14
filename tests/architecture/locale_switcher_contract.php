<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root.'/'.$path;
    if (!is_file($full)) throw new RuntimeException('Missing localization contract file: '.$path);
    return (string) file_get_contents($full);
};
$routes = $read('symfony/config/routes.yaml');
$services = $read('symfony/config/services.yaml');
$base = $read('symfony/templates/base.html.twig');
$shell = $read('symfony/templates/experience/workspace_shell.html.twig');
$controller = $read('symfony/src/Web/Experience/Locale/LocaleController.php');
$subscriber = $read('symfony/src/Web/Experience/Locale/LocaleSubscriber.php');
$config = $read('symfony/config/packages/translation.yaml');
$uk = $read('symfony/translations/messages.uk.yaml');
$en = $read('symfony/translations/messages.en.yaml');

foreach (['cos_web_locale_switch:', 'path: /workspace/locale', 'LocaleController::change', 'methods: [POST]'] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Locale switch route contract missing '.$needle);
}
foreach (['LocaleController', 'controller.service_arguments'] as $needle) {
    if (!str_contains($services, $needle)) throw new RuntimeException('Locale controller service registration missing '.$needle);
}
foreach (['csrf_token(\'cos_locale_switch\')', 'name="locale"', 'value="uk"', 'value="en"', 'submitLocale', 'shell.title|trans'] as $needle) {
    if (!str_contains($shell, $needle)) throw new RuntimeException('Global language switcher contract missing '.$needle);
}
foreach (["new CsrfToken('cos_locale_switch'", "set('cos_locale'", 'safeReturnPath', "['uk', 'en']"] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Locale persistence/security contract missing '.$needle);
}
foreach (['KernelEvents::REQUEST', "['onKernelRequest', 17]", "set('_locale'", "get('cos_locale')"] as $needle) {
    if (!str_contains($subscriber, $needle)) throw new RuntimeException('Request locale negotiation contract missing '.$needle);
}
foreach (['default_locale: uk', "enabled_locales: ['uk', 'en', 'de', 'fr', 'pl']", "fallbacks: ['en']"] as $needle) {
    if (!str_contains($config, $needle)) throw new RuntimeException('Symfony Translator configuration missing '.$needle);
}
if (!str_contains($base, 'app.request.locale')) throw new RuntimeException('HTML lang attribute must follow the resolved request locale.');

foreach ([
    ['symfony/templates/experience/workspace_shell.html.twig', ['shell.language','shell.brand_name']],
    ['symfony/templates/experience/engineering/index.html.twig', ['Агентська розробка','engineering.status.running']],
    ['symfony/templates/experience/engineering/feature.html.twig', ['ВИКОНУЄТЬСЯ','Менеджер / координатор']],
    ['symfony/templates/experience/async/activity_center_frame.html.twig', ['Activity Center','engineering.status.completed']],
    ['symfony/templates/experience/ai/ai_panel_frame.html.twig', ['AI & Agent Center','available']],
] as [$path,$keys]) {
    $template = $read($path);
    foreach ($keys as $key) {
        if (!str_contains($template, $key)) throw new RuntimeException($path.' missing expected translation ID '.$key);
    }
}
foreach ([
    'shell.language','engineering.index.title','engineering.status.running',
    'engineering.terminal.title','engineering.usage.empty_explanation','Activity Center','AI & Agent Center'
] as $key) {
    $quoted = json_encode($key).':';
    if (!str_contains($uk, $quoted) || !str_contains($en, $quoted)) {
        throw new RuntimeException('Required localization catalog entry is missing: '.$key);
    }
}
echo "COS locale switcher and core localization contract passed.\n";
