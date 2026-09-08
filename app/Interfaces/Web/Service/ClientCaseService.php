<?php
declare(strict_types=1);

namespace Interfaces\Web\Service;

use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\Service\ClientCaseCommandService;
use Domains\Sales\Application\Service\SalesInboundService;
use Throwable;

/** @deprecated Compatibility facade for legacy frontend controllers. */
readonly class ClientCaseService
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandService $commands,
        private SalesInboundService $inbound,
    ) {
    }

    public function filters(array $query): array { return $this->readModel->filters($query); }
    public function cases(array $filters): array { return $this->readModel->cases($filters); }
    public function stats(): array { return $this->readModel->stats(); }
    public function case(int $id): ?array { return $this->readModel->case($id); }
    public function inboundRequests(int $caseId): array { return $this->readModel->inboundRequests($caseId); }
    public function activities(int $caseId): array { return $this->readModel->activities($caseId); }
    public function propertyMatches(int $caseId): array { return $this->readModel->propertyMatches($caseId); }
    public function requestMatches(int $caseId): array { return $this->readModel->requestMatches($caseId); }
    public function unlinkedInboundRequests(): array { return $this->readModel->unlinkedInboundRequests(); }
    public function inboundFilters(array $query): array { return $this->readModel->inboundFilters($query); }
    public function inboundInbox(array $filters): array { return $this->readModel->inboundInbox($filters); }
    public function inboundInboxStats(): array { return $this->readModel->inboundInboxStats(); }
    public function leadActivities(int $leadId): array { return $this->readModel->leadActivities($leadId); }
    public function openCaseOptions(): array { return $this->readModel->openCaseOptions(); }
    public function managerOptions(): array { return $this->readModel->managerOptions(); }

    public function create(array $input, ?array $user = null): array
    {
        try {
            $result = $this->commands->create($input, $user);
            if (!$result->ok && $result->code === 'contact_required') return ['ok' => false, 'message' => 'Вкажіть імʼя людини і хоча б один контакт.'];
            return ['ok' => $result->ok, 'message' => 'Кейс створено.', 'case_id' => $result->data['case_id'] ?? null];
        } catch (Throwable $error) { return $this->failed('client-case-create', 'Кейс не вдалося створити.', $error); }
    }

    public function update(int $caseId, array $input, ?array $user = null): array
    {
        try {
            $result = $this->commands->update($caseId, $input, $user);
            return ['ok' => $result->ok, 'message' => $result->ok ? 'Кейс оновлено.' : 'Кейс не знайдено.'];
        } catch (Throwable $error) { return $this->failed('client-case-update', 'Кейс не вдалося оновити.', $error); }
    }

    public function quickUpdate(int $caseId, array $input, ?array $user = null): array
    {
        try {
            $result = $this->commands->quickUpdate($caseId, $input, $user);
            return ['ok' => $result->ok, 'message' => $result->ok ? 'Кейс оновлено.' : 'Кейс не знайдено.'];
        } catch (Throwable $error) { return $this->failed('client-case-quick-update', 'Кейс не вдалося оновити.', $error); }
    }

    public function addActivity(int $caseId, array $input, ?array $user = null): array
    {
        try {
            $result = $this->commands->addActivity($caseId, $input, $user);
            return ['ok' => $result->ok, 'message' => $result->ok ? 'Дію додано.' : 'Кейс не знайдено.'];
        } catch (Throwable $error) { return $this->failed('client-case-activity', 'Дію не вдалося додати.', $error); }
    }

    public function attachInboundRequest(int $caseId, int $requestId, ?array $user = null): array
    {
        try {
            $result = $this->inbound->attachRequest($caseId, $requestId, $user);
            $message = $result->ok ? 'Заявку привʼязано до кейсу.' : ($result->code === 'case_not_found' ? 'Кейс не знайдено.' : 'Заявку не знайдено.');
            return ['ok' => $result->ok, 'message' => $message];
        } catch (Throwable $error) { return $this->failed('client-case-attach-inbound-request', 'Заявку не вдалося привʼязати.', $error); }
    }

    public function updateInboundRequest(int $requestId, array $input, ?array $user = null): array
    {
        try {
            $result = $this->inbound->updateRequest($requestId, $input, $user);
            return ['ok' => $result->ok, 'message' => $result->ok ? 'Заявку оновлено.' : 'Заявку не знайдено.', 'case_id' => $result->data['case_id'] ?? null];
        } catch (Throwable $error) {
            $this->logError('client-case-inbound-update', $error);
            return ['ok' => false, 'message' => 'Заявку не вдалося оновити.', 'case_id' => null];
        }
    }

    public function createCaseFromInboundRequest(int $requestId, array $input = [], ?array $user = null): array
    {
        try {
            $result = $this->inbound->createCaseFromRequest($requestId, $input, $user);
            $message = match ($result->code) {
                'already_attached' => 'Заявка вже привʼязана до кейса.', 'request_not_found' => 'Заявку не знайдено.', default => 'Кейс створено із заявки.',
            };
            return ['ok' => $result->ok, 'message' => $message, 'case_id' => $result->data['case_id'] ?? null];
        } catch (Throwable $error) {
            $this->logError('client-case-create-from-inbound-request', $error);
            return ['ok' => false, 'message' => 'Кейс із заявки не вдалося створити.', 'case_id' => null];
        }
    }

    public function addPropertyMatch(int $caseId, int $propertyId, array $input, ?array $user = null): array
    {
        try {
            $result = $this->commands->addPropertyMatch($caseId, $propertyId, $input, $user);
            $message = match ($result->code) { 'case_not_found' => 'Кейс не знайдено.', 'property_not_found' => 'Обʼєкт не знайдено.', default => 'Обʼєкт додано до кейсу.' };
            return ['ok' => $result->ok, 'message' => $message, 'case_id' => $result->data['case_id'] ?? $caseId];
        } catch (Throwable $error) { return $this->failed('client-case-property-match-add', 'Обʼєкт не вдалося додати до кейсу.', $error); }
    }

    public function updatePropertyMatch(int $matchId, array $input, ?array $user = null): array
    {
        try {
            $result = $this->commands->updatePropertyMatch($matchId, $input, $user);
            return ['ok' => $result->ok, 'message' => $result->ok ? 'Підбір оновлено.' : 'Підбір не знайдено.', 'case_id' => $result->data['case_id'] ?? null];
        } catch (Throwable $error) {
            $this->logError('client-case-property-match-update', $error);
            return ['ok' => false, 'message' => 'Підбір не вдалося оновити.', 'case_id' => null];
        }
    }

    public function registerInboundRequest(int $caseId, int $requestId): void { $this->inbound->registerRequest($caseId, $requestId); }

    public function addInboundPropertyMatch(int $caseId, int $propertyId): void
    {
        if ($this->inbound->resolvePropertyId($propertyId) === null) return;
        $this->commands->addPropertyMatch($caseId, $propertyId, ['match_status' => 'interested', 'note' => 'Обʼєкт із вхідної заявки']);
    }

    public function inboundPropertyId(mixed $value): ?int { return $this->inbound->resolvePropertyId($value); }

    public function ensurePersonAndCaseFromInbound(array $input, ?array $user = null): array
    {
        try {
            $result = $this->inbound->ensureCase($input, $user);
            return ['person_id' => $result->data['person_id'] ?? null, 'client_case_id' => $result->data['client_case_id'] ?? null];
        } catch (Throwable $error) {
            $this->logError('client-case-ensure-from-inbound', $error);
            return ['person_id' => null, 'client_case_id' => null];
        }
    }

    private function failed(string $label, string $message, Throwable $error): array
    {
        $this->logError($label, $error);
        return ['ok' => false, 'message' => $message];
    }

    private function logError(string $label, Throwable $error): void
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $directory = $basePath . '/tmp/logs';
        if (!is_dir($directory)) @mkdir($directory, 0775, true);
        @file_put_contents($directory . '/frontend.log', sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $error->getMessage(), PHP_EOL), FILE_APPEND);
    }
}
