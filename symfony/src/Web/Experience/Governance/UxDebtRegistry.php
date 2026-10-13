<?php
declare(strict_types=1);

namespace App\Web\Experience\Governance;

use App\Web\Experience\Registry\PageContract;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class UxDebtRegistry
{
    private const SEVERITIES = ['BLOCKER', 'MAJOR', 'MINOR', 'COSMETIC'];
    private const STATUSES = ['OPEN', 'CLOSED'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/ux-debt.yaml')]
        private string $manifest,
    ) {}

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $document = Yaml::parseFile($this->manifest);
        if (!is_array($document)) {
            throw new RuntimeException('UX Debt registry is invalid.');
        }

        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('UX Debt entry must be a mapping.');
            }
            $id = trim((string) ($item['id'] ?? ''));
            $severity = strtoupper(trim((string) ($item['severity'] ?? '')));
            $status = strtoupper(trim((string) ($item['status'] ?? '')));
            foreach (['category','scope','problem','exit'] as $field) {
                if (trim((string) ($item[$field] ?? '')) === '') {
                    throw new RuntimeException(sprintf('UX Debt %s has empty %s.', $id ?: '(unknown)', $field));
                }
            }
            if ($id === '' || isset($seen[$id])) {
                throw new RuntimeException('UX Debt IDs must be non-empty and unique.');
            }
            if (!in_array($severity, self::SEVERITIES, true)) {
                throw new RuntimeException('Unsupported UX Debt severity: '.$severity);
            }
            if (!in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('Unsupported UX Debt status: '.$status);
            }
            $seen[$id] = true;
            $item['severity'] = $severity;
            $item['status'] = $status;
            $result[] = $item;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function open(): array
    {
        return array_values(array_filter($this->all(), static fn (array $item): bool => $item['status'] === 'OPEN'));
    }

    /** @return list<array<string,mixed>> */
    public function blocking(): array
    {
        return array_values(array_filter($this->open(), static fn (array $item): bool => $item['severity'] === 'BLOCKER'));
    }

    /** @return list<array<string,mixed>> */
    public function forPage(PageContract $page): array
    {
        $id = strtolower($page->id->value);
        $domain = strtolower($page->domain);
        return array_values(array_filter($this->open(), static function (array $item) use ($id, $domain): bool {
            $scope = strtolower(trim((string) ($item['scope'] ?? '')));
            return $scope === 'registry' || $scope === $id || $scope === $domain;
        }));
    }
}
