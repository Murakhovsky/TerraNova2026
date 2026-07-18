<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Models\Crm\InboundRequest;
use Common\Services\DatabaseService;
use Throwable;

class InboundRequestService
{
    private const SUCCESS_MESSAGE = 'Заявку збережено. Менеджер Terra Nova отримає її в CRM.';
    private const VALIDATION_MESSAGE = 'Заповніть імʼя та хоча б один контакт.';
    private const ERROR_MESSAGE = 'Заявку не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';

    public function __construct(private ?ClientCaseService $clientCases = null, private ?DatabaseService $database = null)
    {
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

            $request = new InboundRequest();
            $request->buyer_id = null;
            $request->person_id = $caseContext['person_id'] ?? null;
            $request->client_case_id = $caseContext['client_case_id'] ?? null;
            $request->property_id = $propertyId;
            $request->full_name = mb_substr($name, 0, 160);
            $request->phone = $phone !== '' ? mb_substr($phone, 0, 50) : null;
            $request->email = $email !== '' ? mb_substr($email, 0, 160) : null;
            $request->role = $this->requestRole((string) ($input['role'] ?? 'buyer'));
            $request->deal_type = $this->requestDealType((string) ($input['deal_type'] ?? $input['request_type'] ?? 'consultation'));
            $request->request_intent = $this->requestIntent((string) ($input['request_intent'] ?? $input['intent'] ?? 'general_contact'));
            $request->message = $message !== '' ? mb_substr($message, 0, 4000) : null;
            $request->preferred_contact = 'any';
            $request->source_page = mb_substr($sourcePage, 0, 255);
            $utm = $this->utmValues($input, $sourcePage);
            $request->utm_source = $utm['utm_source'];
            $request->utm_medium = $utm['utm_medium'];
            $request->utm_campaign = $utm['utm_campaign'];
            $request->utm_content = $utm['utm_content'];
            $request->utm_term = $utm['utm_term'];
            $request->status = 'new';

            if (!$request->save()) {
                $this->logError('inbound-request-validation', implode('; ', $request->getMessages()));

                return ['ok' => false, 'message' => self::ERROR_MESSAGE];
            }

            if (!empty($caseContext['client_case_id'])) {
                $this->clientCases?->registerInboundRequest((int) $caseContext['client_case_id'], (int) $request->id);

                if (!empty($request->property_id)) {
                    $this->clientCases?->addInboundPropertyMatch((int) $caseContext['client_case_id'], (int) $request->property_id);
                }
            }

            $this->recordLeadSubmit((int) $request->id, $propertyId, $sourcePage, $utm);
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
