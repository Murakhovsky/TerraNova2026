<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Domain\Workflow\EngineeringRetryPolicy;

$policy = new EngineeringRetryPolicy();
if (!$policy->mayRetryTechnical(0) || !$policy->mayRetryTechnical(1) || $policy->mayRetryTechnical(2)) throw new RuntimeException('Technical retry policy is incorrect.');
if (!$policy->mayRunReview(2) || $policy->mayRunReview(3)) throw new RuntimeException('Review cycle limit is incorrect.');
if (!$policy->mayRunQa(2) || $policy->mayRunQa(3)) throw new RuntimeException('QA cycle limit is incorrect.');
if (!$policy->mayRunDevelopmentFix(2) || $policy->mayRunDevelopmentFix(3)) throw new RuntimeException('Development fix loop limit is incorrect.');

echo "Engineering retry policy passed.\n";
