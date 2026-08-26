<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;
use Domains\Sales\Automation\Event\LeadCreated;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Throwable;

class InboundRequestService
{
    private const SUCCESS_MESSAGE = 'Заявку збережено. Менеджер Terra Nova отримає її в CRM.';
    private const VALIDATION_MESSAGE = 'Заповніть імʼя та хоча б один контакт.';
    private const ERROR_MESSAGE = 'Заявку не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';

    public function __construct(
        private ?ClientCaseService $clientCases,
        private DatabaseService $database,
        private EventBus $eventBus,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function submit(array $input, string $sourcePage): array
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return ['ok' => true, 'message' => self::SUCCESS_MESSAGE];
        }

        $name = trim((string) ($input['full_name'] ?? $input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $message = trim((string) ($input['message'] ?? $input['comment'] ?? ''));
        $propertyId = $this->clientCases?->inboundPropertyId($input['property_id'] ?? null);
        if ($propertyId) {
            $input['property_id'] = $propertyId;
        } else {
            unset($input['property_id']);
        }

        if ($name === '' || ($phone === '' && $email === '')) {
            return ['ok' => false, 'message' => self::VALIDATION_MESSAGE];
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email або залиште поле порожнім.'];
        }

        try {
            $caseContext = $this->clientCases?->ensurePersonAndCaseFromInbound($input) ?? [
                'person_id' => null,
                'client_case_id' => null,
            ];

            $leadId = $this->transactions->transactional(function () use (
                $input, $sourcePage, $name, $phone, $email, $message, $caseContext
            ): int {
                $statement = $this->database->connection()->prepare('
                    INSERT INTO tn_leads (
                        organization_id, buyer_id, person_id, client_case_id, property_id, full_name, phone, email,
                        role, deal_type, message, preferred_contact, source_page, status
                    ) VALUES (
                        :organization_id, NULL, :person_id, :client_case_id, :property_id, :full_name, :phone, :email,
                        :role, :deal_type, :message, "any", :source_page, "new"
                    )
                ');
                $statement->execute([
                    'organization_id' => $this->organizationId,
                    'person_id' => $caseContext['person_id'] ?? null,
                    'client_case_id' => $caseContext['client_case_id'] ?? null,
                    'property_id' => $this->positiveInt($input['property_id'] ?? null),
                    'full_name' => mb_substr($name, 0, 160),
                    'phone' => $phone !== '' ? mb_substr($phone, 0, 50) : null,
                    'email' => $email !== '' ? mb_substr($email, 0, 160) : null,
                    'role' => $this->requestRole((string) ($input['role'] ?? 'buyer')),
                    'deal_type' => $this->requestDealType((string) ($input['deal_type'] ?? $input['request_type'] ?? 'consultation')),
                    'message' => $message !== '' ? mb_substr($message, 0, 4000) : null,
                    'source_page' => mb_substr($sourcePage, 0, 255),
                ]);
                $leadId = (int) $this->database->connection()->lastInsertId();

                if (!empty($caseContext['client_case_id'])) {
                    $this->clientCases?->registerInboundRequest((int) $caseContext['client_case_id'], $leadId);
                }

                $this->eventBus->publish(LeadCreated::create(
                    bin2hex(random_bytes(16)),
                    $this->organizationId,
                    (string) $leadId,
                    [
                        'client_case_id' => $caseContext['client_case_id'] ?? null,
                        'source_page' => $sourcePage,
                        'status' => 'new',
                    ],
                    new EventMetadata(bin2hex(random_bytes(16)), null, 'SYSTEM', 'public-web'),
                ));

                return $leadId;
            });
        } catch (Throwable $e) {
            $this->logError('inbound-request-exception', $e);

            return ['ok' => false, 'message' => self::ERROR_MESSAGE];
        }

        return ['ok' => true, 'message' => self::SUCCESS_MESSAGE];
    }

    private function recordLeadSubmit(int $leadId, ?int $propertyId, string $sourcePage, array $utm): void
    {
        if (!$this->database || $leadId <= 0) {
            return;
        }

        try {
            $this->database->connection()->prepare('
                INSERT INTO tn_analytics_events (
                    event_type, entity_type, entity_id, property_id, lead_id, source_page,
                    utm_source, utm_medium, utm_campaign, payload
                ) VALUES (
                    "lead_submit", "lead", :entity_id, :property_id, :lead_id, :source_page,
                    :utm_source, :utm_medium, :utm_campaign, :payload
                )
            ')->execute([
                'entity_id' => $leadId,
                'property_id' => $propertyId,
                'lead_id' => $leadId,
                'source_page' => $this->nullable($sourcePage, 255),
                'utm_source' => $utm['utm_source'] ?? null,
                'utm_medium' => $utm['utm_medium'] ?? null,
                'utm_campaign' => $utm['utm_campaign'] ?? null,
                'payload' => json_encode([
                    'utm_content' => $utm['utm_content'] ?? null,
                    'utm_term' => $utm['utm_term'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            $this->logError('lead-submit-analytics', $e);
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function utmValues(array $input, string $sourcePage): array
    {
        $query = [];
        $parts = parse_url($sourcePage);
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $value = fn(string $key, int $limit): ?string => $this->nullable((string) ($input[$key] ?? $query[$key] ?? ''), $limit);

        return [
            'utm_source' => $value('utm_source', 120),
            'utm_medium' => $value('utm_medium', 120),
            'utm_campaign' => $value('utm_campaign', 160),
            'utm_content' => $value('utm_content', 160),
            'utm_term' => $value('utm_term', 160),
        ];
    }

    private function nullable(string $value, int $limit): ?string
    {
        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, $limit) : null;
    }

    private function requestRole(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $roles = [
            'buyer' => 'buyer',
            'покупець' => 'buyer',
            'owner' => 'seller',
            'seller' => 'seller',
            'власник' => 'seller',
            'investor' => 'investor',
            'інвестор' => 'investor',
            'realtor' => 'realtor',
            'рієлтор' => 'realtor',
            'developer' => 'developer',
            'забудовник' => 'developer',
            'partner' => 'partner',
            'партнер' => 'partner',
        ];

        return $roles[$value] ?? 'other';
    }

    private function requestDealType(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $types = [
            'sale' => 'sale',
            'купівля' => 'sale',
            'продаж' => 'sale',
            'rent' => 'rent',
            'оренда' => 'rent',
            'investment' => 'investment',
            'інвестиції' => 'investment',
            'заявка інвестора' => 'investment',
            'consultation' => 'consultation',
            'партнерство' => 'consultation',
        ];

        return $types[$value] ?? 'consultation';
    }

    private function requestIntent(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $intents = [
            'general_contact' => 'general_contact',
            'contact' => 'general_contact',
            'consultation' => 'general_contact',
            'presentation' => 'presentation',
            'viewing' => 'viewing',
            'visit' => 'viewing',
            'showing' => 'viewing',
            'similar_search' => 'similar_search',
            'similar' => 'similar_search',
            'підбір' => 'similar_search',
        ];

        return $intents[$value] ?? 'general_contact';
    }

    private function logError(string $label, Throwable|string $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $message = is_string($error) ? $error : $error->getMessage();
        $entry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $message, PHP_EOL);
        @file_put_contents($directory . '/frontend.log', $entry, FILE_APPEND);
    }
}
