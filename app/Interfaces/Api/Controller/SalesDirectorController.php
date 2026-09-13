<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesDirectorCockpitService;
use Interfaces\Web\Controller\WebController;
use InvalidArgumentException;
use Phalcon\Http\Response;
use Throwable;

final class SalesDirectorController extends WebController
{
    public function overviewAction(): Response
    {
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) {
            return $this->json(403, ['ok' => false, 'error' => 'Sales director access requires manager role.']);
        }

        try {
            $historyDays = max(1, min(366, (int) $this->request->getQuery('history_days', 'int', 30)));
            $forecastDays = max(1, min(366, (int) $this->request->getQuery('forecast_days', 'int', 30)));
            $pipelineId = trim((string) $this->request->getQuery('pipeline_id', 'string', '')) ?: null;
            $asOf = new DateTimeImmutable();

            return $this->json(200, [
                'ok' => true,
                'data' => $this->service()->overview(
                    $this->organization()->id(),
                    $asOf,
                    $historyDays,
                    $forecastDays,
                    $pipelineId,
                ),
            ]);
        } catch (InvalidArgumentException $error) {
            return $this->json(400, ['ok' => false, 'error' => $error->getMessage()]);
        } catch (Throwable $error) {
            $this->di->getShared('cosLogger')->error('sales.director.read_failed', ['error' => $error->getMessage()]);
            return $this->json(500, ['ok' => false, 'error' => 'Sales director cockpit unavailable.']);
        }
    }

    private function service(): SalesDirectorCockpitService
    {
        return $this->di->getShared('salesDirectorCockpit');
    }

    private function json(int $status, array $payload): Response
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}
