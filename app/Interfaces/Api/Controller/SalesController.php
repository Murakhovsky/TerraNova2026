<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use Domains\Sales\Application\UseCase\RecordActionOutcome;
use Domains\Sales\Model\OutcomeAttribution;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\UseCase\ChangeDealStage;
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

        $json = $this->request->getJsonRawBody(true);
        $input = is_array($json) ? $json : (array) $this->request->getPost();
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
        $dealId=(string)($id?:$this->dispatcher->getParam('id'));$user=$this->auth()->currentUser();
        if($user===null||!$this->auth()->isManager($user))return $this->json(403,['ok'=>false,'error'=>'Manager authorization required.']);
        if(!$this->validMutation()||!ctype_digit($dealId)||((int)$dealId)<=0)return $this->json(400,['ok'=>false,'error'=>'Invalid request or CSRF token.']);
        $json=$this->request->getJsonRawBody(true);$input=is_array($json)?$json:(array)$this->request->getPost();$stageId=trim((string)($input['stage_id']??''));
        try {
            if($stageId===''){$code=trim((string)($input['stage']??''));$deal=$this->di->getShared('salesDealRepository')->getForStageChange($this->organization()->id(),$dealId);if($deal===null)return $this->json(404,['ok'=>false,'error'=>'Deal not found.']);$stage=$this->di->getShared('salesPipelineRepository')->findStageByCode($this->organization()->id(),(string)$deal['pipeline_id'],strtoupper($code));$stageId=$stage?->id??'';}
            if($stageId==='')return $this->json(422,['ok'=>false,'error'=>'A valid stage_id is required.']);
            /** @var ChangeDealStage $useCase */$useCase=$this->di->getShared('salesChangeDealStage');$result=$useCase->execute(new ChangeDealStageCommand($this->organization()->id(),$dealId,$stageId,'USER',(string)$user['id'],bin2hex(random_bytes(16))));
            return $result->successful?$this->json(200,['ok'=>true,'data'=>['changed'=>$result->changed,'previous_stage_id'=>$result->previousStageId,'stage_id'=>$result->stageId]]):$this->json(422,['ok'=>false,'error'=>$result->reason]);
        }catch(Throwable $e){return $this->json(422,['ok'=>false,'error'=>$e->getMessage()]);}
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
