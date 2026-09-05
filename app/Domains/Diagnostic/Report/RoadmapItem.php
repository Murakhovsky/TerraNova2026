<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
final readonly class RoadmapItem { public function __construct(public string $period,public string $recommendationId,public string $action,public string $owner,public array $dependencies,public string $successMetric,public string $expectedOutcome){} }
