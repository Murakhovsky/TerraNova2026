<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = file_get_contents($root . '/symfony/templates/experience/federation/goals.html.twig');
$css = file_get_contents($root . '/symfony/assets/styles/golden50.css');
$app = file_get_contents($root . '/symfony/assets/styles/app.css');
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$check(is_string($view) && is_string($css) && is_string($app), 'Golden 50 cockpit assets missing.');
$check(str_contains($view, 'data-golden50-control-center') && str_contains($view, 'data-golden50-create'), 'Operator entrypoint missing.');
$check(str_contains($view, "path('cos_federation_goals_create')"), 'Golden 50 Goal must use existing tenant/CSRF-gated Goal creation.');
$check(str_contains($view, 'name="csrf_token" value="{{ csrfToken }}"'), 'CSRF protection missing.');
$check(str_contains($view, 'name="criterion" value="federation.verified_proposals"') &&
    str_contains($view, 'name="target" value="50"'), 'Reference Golden 50 criteria changed.');
$check(str_contains($view, 'canonicalRolloutEnabled') &&
    str_contains($view, 'data-goal-advanced-create'), 'Rollout guard or existing generic Goal flow missing.');
$check(str_contains($view, 'cos_federation_goals_build_plan') &&
    str_contains($view, 'cos_federation_goals_request_approval') &&
    str_contains($view, 'cos_federation_goals_start_plan') &&
    str_contains($view, 'cos_federation_goals_evaluate'), 'Existing guarded Plan/Run/Outcome route lost.');
$check(str_contains($view, 'цільовим порогом') && str_contains($view, 'поки не має окремого live read-model'),
    'UI cannot imply target counts are verified business results.');
$check(str_contains($view, '/workspace/capabilities') &&
    str_contains($view, '/workspace/capabilities/intents'), 'Canonical capability library discovery missing.');
$check(substr_count($view, '<form') === substr_count($view, '</form>') &&
    substr_count($view, '<details') === substr_count($view, '</details>'), 'Unbalanced HTML forms/disclosures.');
$check(str_contains($app, "@import './golden50.css';") &&
    str_contains($css, '@media (max-width: 650px)'), 'Responsive cockpit stylesheet not registered.');
echo "Golden 50 operator cockpit contract PASS.\n";
