<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Contract;

use DateTimeImmutable;

interface DiagnosticRuntimeRepositoryInterface
{
    public function create(string $organizationId, string $sessionId, string $mode, array $state, ?string $parentSessionId, DateTimeImmutable $at): void;
    public function get(string $organizationId, string $sessionId): ?array;
    public function saveState(string $organizationId, string $sessionId, array $state, ?string $currentQuestionId, int $revision): void;
    public function complete(string $organizationId, string $sessionId, DateTimeImmutable $at): void;
    public function saveReport(string $organizationId, string $sessionId, array $report, int $stateRevision, DateTimeImmutable $at): int;
    public function report(string $organizationId, string $sessionId): ?array;
    public function saveRecommendation(string $organizationId, string $sessionId, string $recommendationId, array $payload, string $status, DateTimeImmutable $at, ?string $actionId = null): void;
    public function recommendation(string $organizationId, string $sessionId, string $recommendationId): ?array;
    public function recommendations(string $organizationId, string $sessionId): array;
    public function appendMeasurement(string $organizationId, string $sessionId, string $actionId, string $metricCode, float $value, ?string $evidenceId, DateTimeImmutable $at): void;
    public function measurements(string $organizationId, string $sessionId): array;
    public function schedule(string $organizationId, string $sourceSessionId, int $intervalDays, DateTimeImmutable $dueAt, DateTimeImmutable $at): array;
    public function dueSchedules(string $organizationId, DateTimeImmutable $at, int $limit = 100): array;
    public function pendingScheduleForSource(string $organizationId, string $sourceSessionId): ?array;
    public function markScheduleStarted(string $organizationId, string $scheduleId, string $followupSessionId, DateTimeImmutable $at): void;
}
