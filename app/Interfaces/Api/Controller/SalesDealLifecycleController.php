<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Interfaces\Web\Controller\WebController;
use Phalcon\Http\Response;
use Throwable;

final class SalesDealLifecycleController extends WebController
{
    public function lostReasonsAction(?string $id = null): Response
    {
        $pipelineId = trim((string) ($id ?: $this->dispatcher->getParam('id')));
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $pipelineId)) return $this->json(400, ['ok' => false, 'error' => 'Invalid pipeline id.']);
        try {
            $reasons = $this->di->getShared('salesPipelineRepository')->lostReasons($this->organization()->id(), $pipelineId);
            return $this->json(200, ['ok' => true, 'data' => $reasons]);
        } catch (Throwable $exception) {
            return $this->json(422, ['ok' => false, 'error' => $exception->getMessage()]);
        }
    }

    public function loseAction(?string $id = null): Response
    {
        $dealId = trim((string) ($id ?: $this->dispatcher->getParam('id')));
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        if (!$this->validMutation() || !ctype_digit($dealId) || (int) $dealId <= 0) return $this->json(400, ['ok' => false, 'error' => 'Invalid request or CSRF token.']);

        $input = $this->input();
        $reasonId = trim((string) ($input['lost_reason_id'] ?? ''));
        if ($reasonId === '') return $this->json(422, ['ok' => false, 'error' => 'lost_reason_id is required.']);

        try {
            $organizationId = $this->organization()->id();
            $deal = $this->di->getShared('salesDealRepository')->getForStageChange($organizationId, $dealId);
            if ($deal === null) return $this->json(404, ['ok' => false, 'error' => 'Deal not found.']);

            $pipeline = $this->di->getShared('salesPipelineRepository')->getPipeline($organizationId, (string) $deal['pipeline_id']);
            if ($pipeline === null) return $this->json(422, ['ok' => false, 'error' => 'Deal pipeline is unavailable.']);
            $lostStage = null;
            foreach ($pipeline->stages as $stage) {
                if ($stage->isLost) { $lostStage = $stage; break; }
            }
            if ($lostStage === null) return $this->json(422, ['ok' => false, 'error' => 'Pipeline has no LOST stage.']);

            /** @var ChangeDealStage $useCase */
            $useCase = $this->di->getShared('salesChangeDealStage');
            $result = $useCase->execute(new ChangeDealStageCommand(
                $organizationId,
                $dealId,
                $lostStage->id,
                'USER',
                (string) $user['id'],
                bin2hex(random_bytes(16)),
                $reasonId,
                trim((string) ($input['lost_reason_note'] ?? '')) ?: null,
            ));
            return $result->successful
                ? $this->json(200, ['ok' => true, 'data' => ['changed' => $result->changed, 'stage_id' => $result->stageId, 'lost_reason_id' => $reasonId]])
                : $this->json(422, ['ok' => false, 'error' => $result->reason]);
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
}
