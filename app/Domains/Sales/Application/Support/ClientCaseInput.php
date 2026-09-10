<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Support;

use Domains\Sales\Model\ClientCaseStatus;
use Domains\Sales\Model\ClientCaseType;
use Domains\Sales\Model\LeadStatus;
use Domains\Sales\Model\SalesCurrency;
use Domains\Sales\Model\SalesPriority;

final class ClientCaseInput
{
    public static function allowed(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function limit(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    public static function nullable(string $value, int $limit): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    public static function email(string $value): ?string
    {
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr($value, 0, 160) : null;
    }

    public static function text(string $value): ?string
    {
        return self::nullable($value, 4000);
    }

    public static function decimal(mixed $value): ?string
    {
        return $value !== null && $value !== '' && is_numeric($value)
            ? number_format((float) $value, 2, '.', '')
            : null;
    }

    public static function score(mixed $value): ?int
    {
        return $value !== null && $value !== '' && is_numeric($value)
            ? max(0, min(100, (int) $value))
            : null;
    }

    public static function dateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    public static function currency(string $value): string
    {
        $value = strtoupper(trim($value));
        return SalesCurrency::accepts($value) ? $value : SalesCurrency::Usd->value;
    }

    public static function parametersJson(array $input): ?string
    {
        $parameters = [];
        foreach (['property_type', 'locations', 'rooms_min', 'must_have', 'nice_to_have'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') $parameters[$key] = $value;
        }
        return $parameters ? json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    public static function caseData(
        array $input,
        string $name,
        ?int $managerId,
        ?int $propertyTypeId,
        ?int $locationId,
        ?array $existing = null,
    ): array {
        $status = self::allowed(
            (string) ($input['status'] ?? ($existing['status'] ?? ClientCaseStatus::Active->value)),
            ClientCaseStatus::values(),
            (string) ($existing['status'] ?? ClientCaseStatus::Active->value),
        );
        return [
            'type' => self::allowed(
                (string) ($input['type'] ?? ($existing['type'] ?? ClientCaseType::Buy->value)),
                ClientCaseType::values(),
                (string) ($existing['type'] ?? ClientCaseType::Buy->value),
            ),
            'title' => self::caseTitle($input, $name),
            'status' => $status,
            'priority' => self::allowed(
                (string) ($input['priority'] ?? ($existing['priority'] ?? SalesPriority::Normal->value)),
                SalesPriority::values(),
                (string) ($existing['priority'] ?? SalesPriority::Normal->value),
            ),
            'assigned_user_id' => $managerId,
            'source' => self::nullable((string) ($input['source'] ?? ($existing['source'] ?? 'manual')), 120),
            'property_type_id' => $propertyTypeId,
            'location_id' => $locationId,
            'budget_min' => self::decimal($input['budget_min'] ?? ($existing['budget_min'] ?? null)),
            'budget_max' => self::decimal($input['budget_max'] ?? ($existing['budget_max'] ?? null)),
            'currency' => self::currency((string) ($input['currency'] ?? ($existing['currency'] ?? 'USD'))),
            'area_min' => self::decimal($input['area_min'] ?? ($existing['area_min'] ?? null)),
            'area_max' => self::decimal($input['area_max'] ?? ($existing['area_max'] ?? null)),
            'description' => self::text((string) ($input['description'] ?? ($existing['description'] ?? ''))),
            'parameters_json' => self::parametersJson($input),
            'next_contact_at' => self::dateTime((string) ($input['next_contact_at'] ?? ($existing['next_contact_at'] ?? ''))),
            'closed_at' => ClientCaseStatus::from($status)->isTerminal()
                ? (($existing['closed_at'] ?? null) ?: date('Y-m-d H:i:s'))
                : null,
        ];
    }

    public static function caseTitle(array $input, string $name): string
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title !== '') return self::limit($title, 220);
        $type = self::allowed(
            (string) ($input['type'] ?? ClientCaseType::Buy->value),
            ClientCaseType::values(),
            ClientCaseType::Buy->value,
        );
        $labels = [
            'buy' => 'Купівля', 'sell' => 'Продаж', 'rent' => 'Оренда', 'lease_out' => 'Здача в оренду',
            'repair' => 'Ремонт', 'investment' => 'Інвестиція', 'management' => 'Управління',
            'inheritance' => 'Спадщина', 'other' => 'Кейс',
        ];
        return self::limit(($labels[$type] ?? 'Кейс') . ($name !== '' ? ': ' . $name : ''), 220);
    }

    public static function caseTypeFromInbound(array $input): string
    {
        $role = mb_strtolower(trim((string) ($input['role'] ?? '')));
        $dealType = mb_strtolower(trim((string) ($input['deal_type'] ?? $input['request_type'] ?? '')));
        if ($dealType === 'rent') {
            return in_array($role, ['owner', 'seller'], true)
                ? ClientCaseType::LeaseOut->value
                : ClientCaseType::Rent->value;
        }
        if (in_array($role, ['owner', 'seller'], true)) return ClientCaseType::Sell->value;
        if ($role === 'investor' || $dealType === 'investment') return ClientCaseType::Investment->value;
        return ClientCaseType::Buy->value;
    }

    public static function caseTitleFromInbound(array $input, string $name): string
    {
        $labels = ['presentation' => 'Презентація', 'viewing' => 'Перегляд', 'similar_search' => 'Підбір схожих', 'general_contact' => 'Загальний контакт'];
        $propertyId = (int) ($input['property_id'] ?? 0);
        if ($propertyId > 0) {
            $intent = $labels[(string) ($input['request_intent'] ?? 'general_contact')] ?? 'Заявка';
            return self::limit($intent . ' по обʼєкту #' . $propertyId . ': ' . $name, 220);
        }
        return self::caseTitle(['type' => self::caseTypeFromInbound($input)], $name);
    }

    public static function leadStatusLabel(string $status): string
    {
        return [
            LeadStatus::New->value => 'Нова',
            LeadStatus::Contacted->value => 'Контакт був',
            LeadStatus::Qualified->value => 'Кваліфікована',
            LeadStatus::ViewingPlanned->value => 'Перегляд заплановано',
            LeadStatus::Viewing->value => 'Перегляд',
            LeadStatus::Negotiation->value => 'Переговори',
            LeadStatus::Won->value => 'Успіх',
            LeadStatus::Lost->value => 'Втрачена',
            LeadStatus::Disqualified->value => 'Дискваліфікована',
            LeadStatus::Spam->value => 'Спам',
            LeadStatus::Closed->value => 'Закрита',
        ][$status] ?? 'Заявка';
    }

    /** @return array{stage:string,status:string} */
    public static function caseStateForLead(string $leadStatus): array
    {
        $stage = [
            LeadStatus::New->value => 'NEW',
            LeadStatus::Contacted->value => 'CONTACTED',
            LeadStatus::Qualified->value => 'QUALIFIED',
            LeadStatus::ViewingPlanned->value => 'MEETING',
            LeadStatus::Viewing->value => 'MEETING',
            LeadStatus::Negotiation->value => 'NEGOTIATION',
            LeadStatus::Won->value => 'WON',
            LeadStatus::Lost->value => 'LOST',
            LeadStatus::Spam->value => 'LOST',
            LeadStatus::Closed->value => 'LOST',
        ][$leadStatus] ?? 'NEW';
        $status = match ($leadStatus) {
            LeadStatus::Won->value => ClientCaseStatus::Closed->value,
            LeadStatus::Lost->value, LeadStatus::Spam->value, LeadStatus::Closed->value => ClientCaseStatus::Lost->value,
            default => ClientCaseStatus::Active->value,
        };
        return ['stage' => $stage, 'status' => $status];
    }
}
