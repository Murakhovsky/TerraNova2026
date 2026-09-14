<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\RecordActionOutcome;
use Domains\Sales\Model\OutcomeAttribution;
use Interfaces\Web\Controller\WebController;
use Phalcon\Http\Response;
use Throwable;

final class SalesController extends WebController
{
    public function dashboardAction(): Response { return $this->read(fn ($q, $org, $user) => $q->dashboard($org, (int) $user['id'])); }
    public function leadsAction(): Response { return $this->read(fn ($q, $org) => $q->leads($org, (array) $this->request->getQuery())); }
    public function dealsAction(): Response { return $this->read(fn ($q, $org) => $q->deals($org, (array) $this->request->getQuery())); }
    public function pipelinesAction(): Response { return $this->read(fn ($q, $org) => $q->pipelines($org)); }
    public function todayAction(): Response { return $this->read(fn ($q, $org, $user) => $q->today($org, (int) $user['id'])); }
    public function metricsAction(): Response { return $this->read(fn ($q, $org) => $q->metrics($org, (int) $this->request->getQuery('days', 'int', 30))); }

    public function recordOutcomeAction(?string $id = null): Response
    {
        $actionId = (string) ($id ?: $this->dispatcher->getParam('id'));
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        if (!$this->validMutation() || !preg_match('/^[a-f0-9]{32}$/', $actionId)) return $this->json(400, ['ok' => false, 'error' => 'Invalid request or CSRF token.']);

        $input = $this->input();
        $metric = strtolower(trim((string) ($input['metric'] ?? '')));
        $attribution = OutcomeAttribution::tryFrom(strtoupper((string) ($input['attribution_type'] ?? 'MANUAL')));
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,159}$/', $metric) || $attribution === null) {
            return $this->json(422, ['ok' => false, 'error' => 'Invalid outcome metric or attribution type.']);
        }

        try {
            $value = $this->decodeJsonValue($input['value'] ?? true);
            $evidence = $this->decodeJsonValue($input['evidence'] ?? []);
            if (!is_array($evidence)) return $this->json(422, ['ok' => false, 'error' => 'Outcome evidence must be a JSON object or array.']);
            $measuredAt = new DateTimeImmutable((string) ($input['measured_at'] ?? 'now'));
            /** @var RecordActionOutcome $useCase */
            $useCase = $this->di->getShared('salesRecordActionOutcome');
            $outcomeId = $useCase->execute(new RecordActionOutcomeCommand(
                $this->organization()->id(), $actionId, $metric, $value, $attribution, $evidence, $measuredAt,
                bin2hex(random_bytes(16)), 'user', (string) $user['id'],
            ));
            return $this->json(201, ['ok' => true, 'data' => ['id' => $outcomeId]]);
        } catch (Throwable $exception) {
            return $this->json(422, ['ok' => false, 'error' => $exception->getMessage()]);
        }
    }

    public function dealAction(?string $id = null): Response
    {
        $dealId = (int) ($id ?: $this->dispatcher->getParam('id'));
        if ($dealId <= 0) return $this->json(400, ['ok' => false, 'error' => 'Invalid deal id.']);
        return $this->read(function (SalesWorkspaceReadModelInterface $query, string $organizationId) use ($dealId): array {
            $deal = $query->deal($organizationId, $dealId);
            if ($deal === null) return ['_status' => 404, 'error' => 'Deal not found.'];
            return $deal;
        });
    }

    public function timelineAction(?string $id = null): Response
    {
        $dealId = (int) ($id ?: $this->dispatcher->getParam('id'));
        if ($dealId <= 0) return $this->json(400, ['ok' => false, 'error' => 'Invalid deal id.']);
        return $this->read(fn ($q, $org) => $q->timeline($org, $dealId, (int) $this->request->getQuery('limit', 'int', 100)));
    }

    public function intelligenceAction(?string $id = null): Response
    {
        $dealId = (int) ($id ?: $this->dispatcher->getParam('id'));
        if ($dealId <= 0) return $this->json(400, ['ok' => false, 'error' => 'Invalid deal id.']);
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        try {
            return $this->json(200, ['ok' => true, 'data' => $this->di->getShared('cosOperationsReadModel')->dealIntelligence($this->organization()->id(), $dealId)]);
        } catch (Throwable) {
            return $this->json(500, ['ok' => false, 'error' => 'Sales intelligence is unavailable.']);
        }
    }

    public function stageAction(?string $id = null): Response
    {
        $dealId = (string) ($id ?: $this->dispatcher->getParam('id'));
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        if (!$this->validMutation() || !ctype_digit($dealId) || ((int) $dealId) <= 0) return $this->json(400, ['ok' => false, 'error' => 'Invalid request or CSRF token.']);
        $input = $this->input();
        $stageId = trim((string) ($input['stage_id'] ?? ''));
        try {
            if ($stageId === '') {
                $code = trim((string) ($input['stage'] ?? ''));
                $deal = $this->di->getShared('salesDealRepository')->getForStageChange($this->organization()->id(), $dealId);
                if ($deal === null) return $this->json(404, ['ok' => false, 'error' => 'Deal not found.']);
                $stage = $this->di->getShared('salesPipelineRepository')->findStageByCode($this->organization()->id(), (string) $deal['pipeline_id'], strtoupper($code));
                $stageId = $stage?->id ?? '';
            }
            if ($stageId === '') return $this->json(422, ['ok' => false, 'error' => 'A valid stage_id is required.']);
            /** @var ChangeDealStage $useCase */
            $useCase = $this->di->getShared('salesChangeDealStage');
            $result = $useCase->execute(new ChangeDealStageCommand($this->organization()->id(), $dealId, $stageId, 'USER', (string) $user['id'], bin2hex(random_bytes(16))));
            return $result->successful
                ? $this->json(200, ['ok' => true, 'data' => ['changed' => $result->changed, 'previous_stage_id' => $result->previousStageId, 'stage_id' => $result->stageId]])
                : $this->json(422, ['ok' => false, 'error' => $result->reason]);
        } catch (Throwable $e) {
            return $this->json(422, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function quickUpdateAction(?string $id = null): Response
    {
        return $this->mutateDeal($id, function (int $dealId, array $input, array $user): Response {
            $changes = array_intersect_key($input, array_flip(['priority', 'next_contact_at']));
            if ($changes === []) return $this->json(422, ['ok' => false, 'error' => 'priority or next_contact_at is required.']);
            $result = $this->di->getShared('salesClientCaseService')->quickUpdate($dealId, $changes, $user);
            return $result->ok
                ? $this->json(200, ['ok' => true, 'data' => ['code' => $result->code, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->code]);
        });
    }

    public function activityAction(?string $id = null): Response
    {
        return $this->mutateDeal($id, function (int $dealId, array $input, array $user): Response {
            $type = strtolower(trim((string) ($input['activity_type'] ?? 'note')));
            if (!in_array($type, ['note', 'call'], true)) return $this->json(422, ['ok' => false, 'error' => 'Only note or completed call can be logged here.']);
            $input['activity_type'] = $type;
            if ($type === 'call') $input['completed'] = true;
            $result = $this->di->getShared('salesClientCaseService')->addActivity($dealId, $input, $user);
            return $result->ok
                ? $this->json(201, ['ok' => true, 'data' => ['code' => $result->code, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->code]);
        });
    }

    public function followupAction(?string $id = null): Response
    {
        return $this->mutateDeal($id, function (int $dealId, array $input, array $user): Response {
            $title = trim((string) ($input['title'] ?? 'Follow-up'));
            $due = trim((string) ($input['due_at'] ?? ''));
            if ($due === '') return $this->json(422, ['ok' => false, 'error' => 'due_at is required.']);
            try { $dueAt = new DateTimeImmutable($due); } catch (Throwable) { return $this->json(422, ['ok' => false, 'error' => 'Valid due_at is required.']); }
            if ($dueAt <= new DateTimeImmutable()) return $this->json(422, ['ok' => false, 'error' => 'Follow-up must be scheduled in the future.']);
            $correlationId = bin2hex(random_bytes(16));
            $result = $this->di->getShared('salesScheduleDealFollowup')->execute(
                new ScheduleFollowupCommand(
                    $this->organization()->id(), (string) $dealId,
                    mb_substr($title !== '' ? $title : 'Follow-up', 0, 180),
                    trim((string) ($input['body'] ?? '')) !== '' ? mb_substr(trim((string) $input['body']), 0, 4000) : null,
                    $dueAt, 'user:' . $user['id'] . ':' . $correlationId,
                ),
                $correlationId, 'USER', (string) $user['id'],
            );
            return $result->successful
                ? $this->json(201, ['ok' => true, 'data' => ['followup_id' => $result->externalId, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->error ?? 'Follow-up scheduling failed.']);
        });
    }

    public function meetingAction(?string $id = null): Response
    {
        return $this->mutateDeal($id, function (int $dealId, array $input, array $user): Response {
            $scheduled = trim((string) ($input['scheduled_at'] ?? ''));
            if ($scheduled === '') return $this->json(422, ['ok' => false, 'error' => 'scheduled_at is required.']);
            try { $at = new DateTimeImmutable($scheduled); } catch (Throwable) { return $this->json(422, ['ok' => false, 'error' => 'Valid scheduled_at is required.']); }
            if ($at <= new DateTimeImmutable()) return $this->json(422, ['ok' => false, 'error' => 'Meeting must be scheduled in the future.']);
            $key = bin2hex(random_bytes(16));
            $result = $this->di->getShared('salesOperationService')->scheduleMeeting(
                $this->organization()->id(), (string) $dealId,
                mb_substr(trim((string) ($input['title'] ?? 'Sales meeting')) ?: 'Sales meeting', 0, 180),
                $at, 'user:' . $user['id'] . ':' . $key,
                [
                    'location' => trim((string) ($input['location'] ?? '')) ?: null,
                    'channel' => trim((string) ($input['channel'] ?? '')) ?: null,
                    'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
                    'actor_type' => 'USER',
                    'actor_id' => (string) $user['id'],
                ],
            );
            return $result->successful
                ? $this->json(201, ['ok' => true, 'data' => ['meeting_id' => $result->externalId, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->error ?? 'Meeting scheduling failed.']);
        });
    }

    public function ownerAction(?string $id = null): Response
    {
        return $this->mutateDeal($id, function (int $dealId, array $input, array $user): Response {
            $ownerId = (int) ($input['owner_id'] ?? 0);
            if ($ownerId <= 0) return $this->json(422, ['ok' => false, 'error' => 'A valid owner_id is required.']);
            $result = $this->di->getShared('salesAssignDealOwner')->execute(
                $this->organization()->id(), (string) $dealId, $ownerId,
                bin2hex(random_bytes(16)), 'USER', (string) $user['id'],
            );
            return $result->successful
                ? $this->json(200, ['ok' => true, 'data' => ['owner_id' => $ownerId, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->error ?? 'Owner assignment failed.']);
        });
    }

    private function read(callable $reader): Response
    {
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        try {
            /** @var SalesWorkspaceReadModelInterface $query */
            $query = $this->di->getShared('salesWorkspaceReadModel');
            $data = $reader($query, $this->organization()->id(), $user);
            $status = is_array($data) ? (int) ($data['_status'] ?? 200) : 200;
            if (is_array($data)) unset($data['_status']);
            return $this->json($status, $status >= 400 ? ['ok' => false] + $data : ['ok' => true, 'data' => $data]);
        } catch (Throwable) {
            return $this->json(500, ['ok' => false, 'error' => 'Sales workspace query failed.']);
        }
    }

    private function mutateDeal(?string $id, callable $handler): Response
    {
        $dealId = (string) ($id ?: $this->dispatcher->getParam('id'));
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        if (!$this->validMutation() || !ctype_digit($dealId) || (int) $dealId <= 0) return $this->json(400, ['ok' => false, 'error' => 'Invalid request or CSRF token.']);
        try {
            return $handler((int) $dealId, $this->input(), $user);
        } catch (Throwable $exception) {
            return $this->json(422, ['ok' => false, 'error' => $exception->getMessage()]);
        }
    }

    private function input(): array
    {
        $json = $this->request->getJsonRawBody(true);
        return is_array($json) ? $json : (array) $this->request->getPost();
    }

    private function json(int $status, array $payload): Response
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
