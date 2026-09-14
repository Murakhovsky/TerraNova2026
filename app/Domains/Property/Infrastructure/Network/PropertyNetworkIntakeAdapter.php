<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Network;

use Domains\Property\Application\Contract\PropertyNetworkIntakePort;
use Domains\Property\Application\Contract\PropertySubmissionRepositoryInterface;
use Domains\Property\Network\PropertyNetworkRecord;
use InvalidArgumentException;

final readonly class PropertyNetworkIntakeAdapter implements PropertyNetworkIntakePort
{
    public function __construct(private PropertySubmissionRepositoryInterface $submissions) {}

    public function import(string $organizationId, array $connector, PropertyNetworkRecord $record): int
    {
        $payload = $record->payload;
        $propertyType = trim((string) ($payload['property_type'] ?? $payload['type_code'] ?? ''));
        $city = trim((string) ($payload['city'] ?? $payload['location_name'] ?? ''));
        if ($propertyType === '' || $city === '') {
            throw new InvalidArgumentException('Network Property intake requires property_type/type_code and city/location_name.');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') $title = $propertyType . ' ' . $record->externalId;

        return $this->submissions->create([
            'submission_ref' => 'NW-' . substr(hash('sha256', $organizationId . '|' . ($connector['connector_id'] ?? '') . '|' . $record->key() . '|' . $record->payloadHash()), 0, 37),
            'source_type' => 'network',
            'deal_type' => $this->dealType((string) ($payload['deal_type'] ?? $payload['transaction_type'] ?? 'sale')),
            'property_type' => mb_substr($propertyType, 0, 50),
            'title' => mb_substr($title, 0, 220),
            'city' => mb_substr($city, 0, 120),
            'region' => $this->nullable($payload['region'] ?? null, 120),
            'district' => $this->nullable($payload['district'] ?? null, 120),
            'address' => $this->nullable($payload['address'] ?? $payload['formatted_address'] ?? null, 255),
            'price_amount' => $this->number($payload['price_amount'] ?? $payload['asking_price'] ?? null),
            'price_currency' => strtoupper(substr(trim((string) ($payload['price_currency'] ?? $payload['currency'] ?? 'USD')), 0, 3)) ?: 'USD',
            'area_total' => $this->number($payload['area_total'] ?? $payload['total_area'] ?? null),
            'land_area' => $this->number($payload['land_area'] ?? null),
            'rooms' => $this->number($payload['rooms'] ?? null),
            'floor' => $this->integer($payload['floor'] ?? null),
            'floors' => $this->integer($payload['floors'] ?? null),
            'built_year' => $this->integer($payload['built_year'] ?? null),
            'has_3d_tour' => !empty($payload['has_3d_tour']) ? 1 : 0,
            'media_links' => $this->text($payload['media_links'] ?? $payload['media'] ?? null),
            'description' => (string) ($payload['description'] ?? ''),
            'features_text' => $this->text($payload['features_text'] ?? $payload['features'] ?? null),
            'owner_name' => $this->nullable($payload['owner_name'] ?? null, 160),
            'owner_phone' => $this->nullable($payload['owner_phone'] ?? null, 50),
            'owner_email' => $this->nullable($payload['owner_email'] ?? null, 160),
            'preferred_contact' => $this->preferredContact((string) ($payload['preferred_contact'] ?? 'any')),
            'source_page' => mb_substr('network://' . (string) ($connector['connector_id'] ?? 'external') . '/' . rawurlencode($record->externalId), 0, 255),
        ]);
    }

    private function dealType(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['sale','rent','investment'], true) ? $value : 'sale';
    }

    private function preferredContact(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['phone','telegram','email','any'], true) ? $value : 'any';
    }

    private function nullable(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        return (string) $value;
    }
}
