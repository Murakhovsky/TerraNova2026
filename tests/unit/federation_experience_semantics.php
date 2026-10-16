<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/symfony/src/Web/Experience/Adaptive/ExperienceMode.php';
require dirname(__DIR__, 2) . '/symfony/src/Web/Experience/Adaptive/ExperienceSemantic.php';
require dirname(__DIR__, 2) . '/symfony/src/Web/Experience/Adaptive/ExperienceSemanticsCatalog.php';
require dirname(__DIR__, 2) . '/symfony/src/Web/Experience/Adaptive/ExperienceProfile.php';
require dirname(__DIR__, 2) . '/symfony/src/Web/Experience/Adaptive/ExperienceState.php';

use App\Web\Experience\Adaptive\ExperienceMode;
use App\Web\Experience\Adaptive\ExperienceSemantic;
use App\Web\Experience\Adaptive\ExperienceSemanticsCatalog;
use App\Web\Experience\Adaptive\ExperienceProfile;
use App\Web\Experience\Adaptive\ExperienceState;

$approval = new ExperienceSemantic(
    'federation.approval_status', 'component', 'Expose human decisions',
    'primary', ExperienceMode::Result, 1, 'always', ['approval_required'],
);
$trace = new ExperienceSemantic(
    'federation.execution_trace', 'component', 'Inspect exact Action receipts',
    'expert', ExperienceMode::Expert, 100, 'explicit',
);
$catalog = new ExperienceSemanticsCatalog([$trace, $approval]);
if (array_map(static fn ($v) => $v->id, $catalog->all())
    !== ['federation.approval_status', 'federation.execution_trace']
    || $catalog->get('federation.approval_status')?->toArray()['recommended_mode'] !== 'result'
    || array_key_exists('permission', $approval->toArray())
    || array_key_exists('allowed', $approval->toArray())) {
    throw new RuntimeException('Experience semantics became an authorization or inconsistent composition contract.');
}
foreach ([
    static fn () => new ExperienceSemantic('federation.one', 'ui_action', 'Try', 'primary', ExperienceMode::Result, 0, 'on_trigger'),
    static fn () => new ExperienceSemantic('federation.one', 'component', 'Try', 'primary', ExperienceMode::Result, 0, 'always', ['fake_grant']),
    static fn () => new ExperienceSemanticsCatalog([$approval, $approval]),
    static fn () => new ExperienceState('federation.goal', '', ExperienceMode::Process, ['same', 'same']),
    static fn () => new ExperienceProfile('tenant', '', ExperienceMode::Expert),
] as $reject) {
    try {
        $reject();
        throw new RuntimeException('Invalid Experience semantics/profile/state was accepted.');
    } catch (InvalidArgumentException) {}
}
$profile = new ExperienceProfile('tenant-a', 'user-1', ExperienceMode::Result);
$state = new ExperienceState('federation.goal', 'run-1', ExperienceMode::Expert, ['federation.execution_trace']);
if ($profile->defaultMode !== ExperienceMode::Result || $state->version !== 1) {
    throw new RuntimeException('Foundation Experience DTOs are broken.');
}
echo "Experience Semantics v1: versioned descriptors, scoped state and no permission grants passed.\n";
