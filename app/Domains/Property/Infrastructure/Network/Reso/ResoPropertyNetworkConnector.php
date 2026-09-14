<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Network\Reso;

use Domains\Property\Application\Contract\PropertyNetworkConnectorInterface;
use Domains\Property\Network\PropertyNetworkBatch;
use Domains\Property\Network\PropertyNetworkDeliveryResult;
use Domains\Property\Network\PropertyNetworkRecord;
use RuntimeException;

final readonly class ResoPropertyNetworkConnector implements PropertyNetworkConnectorInterface
{
    public const CODE = 'reso_web_api';

    public function __construct(private ResoWebApiTransportInterface $transport) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function pull(array $connector, ?string $cursor, int $limit = 200): PropertyNetworkBatch
    {
        $configurationReference = $this->configurationReference($connector);
        $page = $this->transport->pull($configurationReference, $cursor, max(1, min(1000, $limit)));
        $records = [];

        foreach (($page['records'] ?? []) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('RESO transport returned a non-object record.');
            }

            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : $row;
            $externalId = $this->firstString($row, ['external_id', 'ListingKey', 'PropertyKey', 'MemberKey', 'OfficeKey', 'id']);
            if ($externalId === null) {
                throw new RuntimeException('RESO record is missing a stable external identifier.');
            }

            $resource = $this->firstString($row, ['resource', 'resource_name', 'ResourceName', 'entity_type']) ?? 'Property';
            $deleted = (bool) ($row['deleted'] ?? false)
                || strtoupper((string) ($row['operation'] ?? '')) === PropertyNetworkRecord::DELETE;

            $records[] = new PropertyNetworkRecord(
                externalEntityType: strtolower($resource),
                externalId: $externalId,
                payload: $payload,
                operation: $deleted ? PropertyNetworkRecord::DELETE : PropertyNetworkRecord::UPSERT,
                externalVersion: $this->firstString($row, ['external_version', 'ModificationTimestamp', 'version']),
                observedAt: $this->firstString($row, ['observed_at', 'ModificationTimestamp', 'timestamp']),
            );
        }

        $nextCursor = $this->nullableString($page['next_cursor'] ?? null);
        $hasMore = (bool) ($page['has_more'] ?? false);
        if ($hasMore && $nextCursor === null) {
            throw new RuntimeException('RESO transport reported more data without a continuation cursor.');
        }

        return new PropertyNetworkBatch(
            records: $records,
            nextCursor: $nextCursor,
            hasMore: $hasMore,
            metadata: is_array($page['metadata'] ?? null) ? $page['metadata'] : [],
        );
    }

    public function push(array $connector, array $records, ?string $cursor = null): PropertyNetworkDeliveryResult
    {
        $configurationReference = $this->configurationReference($connector);
        $outbound = [];
        foreach ($records as $record) {
            if (!$record instanceof PropertyNetworkRecord) {
                throw new RuntimeException('RESO connector accepts only PropertyNetworkRecord instances.');
            }
            $outbound[] = [
                'key' => $record->key(),
                'resource' => $record->externalEntityType,
                'external_id' => $record->externalId,
                'operation' => $record->operation,
                'external_version' => $record->externalVersion,
                'payload' => $record->payload,
            ];
        }

        $result = $this->transport->push($configurationReference, $outbound, $cursor);
        $succeeded = array_values(array_filter(array_map('strval', is_array($result['succeeded'] ?? null) ? $result['succeeded'] : [])));
        $failed = [];
        foreach (is_array($result['failed'] ?? null) ? $result['failed'] : [] as $key => $error) {
            $failed[(string) $key] = (string) $error;
        }

        return new PropertyNetworkDeliveryResult(
            succeededKeys: $succeeded,
            failed: $failed,
            nextCursor: $this->nullableString($result['next_cursor'] ?? null),
        );
    }

    private function configurationReference(array $connector): string
    {
        $reference = trim((string) ($connector['configuration_reference'] ?? ''));
        if ($reference === '') {
            throw new RuntimeException('RESO connector requires configuration_reference; credentials must stay outside Property.');
        }
        return $reference;
    }

    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableString($row[$key] ?? null);
            if ($value !== null) return $value;
        }
        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
