<?php
declare(strict_types=1);

namespace Kernel\Module;

use DomainException;
use JsonException;

/**
 * Pure, deterministic fingerprint of an owning Domain manifest contract and
 * its pinned local input/output schemas. Never resolves or invokes a handler.
 */
final class CapabilityContractFingerprint
{
    /**
     * @return array{capability_id:string,version:string,contract_digest:string,input_schema_digest:string,output_schema_digest:string}
     */
    public static function snapshot(CapabilityContract $contract, ?string $projectRoot = null): array
    {
        $root = $projectRoot ?? dirname(__DIR__, 3);
        $input = self::schema($root, $contract->inputSchema);
        $output = self::schema($root, $contract->outputSchema);
        $payload = [
            'profile' => 'cos-capability-contract-snapshot-v1',
            'contract' => $contract->toArray(),
            'input_schema_digest' => hash('sha256', self::canonical($input)),
            'output_schema_digest' => hash('sha256', self::canonical($output)),
        ];
        return [
            'capability_id' => $contract->id,
            'version' => $contract->version,
            'contract_digest' => hash('sha256', self::canonical($payload)),
            'input_schema_digest' => $payload['input_schema_digest'],
            'output_schema_digest' => $payload['output_schema_digest'],
        ];
    }

    /** @return array<string,mixed> */
    private static function schema(string $root, string $relative): array
    {
        if (!preg_match('#^resources/contracts/[a-z0-9_/-]+\.schema\.json$#D', $relative)
            || str_contains($relative, '..')) {
            throw new DomainException('Capability schema must be a trusted local contract.');
        }
        $base = realpath(rtrim($root, '/') . '/resources/contracts');
        $path = realpath(rtrim($root, '/') . '/' . $relative);
        if ($base === false || $path === false
            || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new DomainException('Capability schema missing or outside trusted contract root.');
        }
        try {
            $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('Malformed capability schema JSON.', previous: $e);
        }
        if (!is_array($data) || array_is_list($data)
            || !isset($data['type']) || !is_string($data['type'])
                && !is_array($data['type'])) {
            throw new DomainException('Capability schema requires an object with an explicit type.');
        }
        return $data;
    }

    private static function canonical(mixed $value): string
    {
        try {
            return json_encode(self::normalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $e) {
            throw new DomainException('Unserializable capability contract.', previous: $e);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::normalize($child);
        }
        return $value;
    }
}
