<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Integration;

use DomainException;

final class SalesIntegrationDefinitionCatalog
{
    public function all(): array
    {
        return [
            [
                'integration_key' => 'crm.aida',
                'provider' => 'aida',
                'capability' => 'CRM',
                'name' => 'AIDA native CRM',
                'category' => 'CRM',
                'directions' => ['INBOUND', 'OUTBOUND'],
                'health_check' => true,
                'credentials_required' => false,
                'config_schema' => [
                    'mode' => ['type' => 'enum', 'values' => ['native'], 'required' => true, 'default' => 'native'],
                    'source_of_truth' => ['type' => 'enum', 'values' => ['aida'], 'required' => true, 'default' => 'aida'],
                ],
            ],
        ];
    }

    public function get(string $integrationKey): array
    {
        foreach ($this->all() as $definition) {
            if ($definition['integration_key'] === $integrationKey) {
                return $definition;
            }
        }
        throw new DomainException('Unsupported Sales integration.');
    }

    public function normalizeConfig(array $definition, array $input): array
    {
        $schema = $definition['config_schema'] ?? [];
        $normalized = [];
        foreach ($schema as $field => $rule) {
            $value = $input[$field] ?? ($rule['default'] ?? null);
            if (($rule['required'] ?? false) && ($value === null || $value === '')) {
                throw new DomainException('Integration configuration field is required: ' . $field);
            }
            if (($rule['type'] ?? null) === 'enum' && $value !== null && !in_array($value, $rule['values'] ?? [], true)) {
                throw new DomainException('Unsupported integration configuration value for ' . $field . '.');
            }
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }
        return $normalized;
    }
}
