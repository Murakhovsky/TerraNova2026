<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
foreach (['personas.yaml','journeys.yaml','terminology.yaml','ux-debt.yaml','design-system/gaps.yaml','priority-policy.yaml','golden-review-checklist.yaml'] as $file) {
    if (!is_file($root.'/resources/experience/'.$file)) throw new RuntimeException('Canonical UX registry or policy missing: '.$file);
}
if (is_file($root.'/resources/experience/design-system-gaps.yaml')) throw new RuntimeException('Duplicate UX design-system registry.');
$review = (string)file_get_contents($root.'/resources/experience/golden-review-checklist.yaml');
if (!str_contains($review, 'Automated browser results never substitute for human review.') || !str_contains($review, 'decision: PENDING')) throw new RuntimeException('Human Golden 8 review gate compromised.');
$release = (string)file_get_contents($root.'/symfony/src/Web/Experience/Release/ExperienceReleaseHardeningService.php');
foreach (["'release_candidate_scope'","'excluded_retired'",'ProductUxGovernanceService'] as $token) if (!str_contains($release,$token)) throw new RuntimeException('UX release gate missing '.$token);
echo "Canonical UX registry and human gate contract PASS.\n";
