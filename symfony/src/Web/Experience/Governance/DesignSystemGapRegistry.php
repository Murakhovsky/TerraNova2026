<?php
declare(strict_types=1);

namespace App\Web\Experience\Governance;

use App\Web\Experience\Registry\PageContract;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class DesignSystemGapRegistry
{
    private const DECISIONS = ['PENDING','APPROVE','REUSE_EXISTING','PAGE_LOCAL_SPECIALIZATION','REJECT'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/design-system/gaps.yaml')]
        private string $manifest,
    ) {}

    /** @return array<string,mixed> */
    public function policy(): array
    {
        $document = $this->document();
        return is_array($document['policy'] ?? null) ? $document['policy'] : [];
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $document = $this->document();
        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Design System Gap entry must be a mapping.');
            }
            $id = trim((string) ($item['id'] ?? ''));
            $need = trim((string) ($item['need'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            $decision = strtoupper(trim((string) ($item['decision'] ?? 'PENDING')));
            $requestedBy = is_array($item['requested_by'] ?? null)
                ? array_values(array_filter(array_map('strval', $item['requested_by'])))
                : [];

            if ($id === '' || isset($seen[$id])) {
                throw new RuntimeException('Design System Gap IDs must be non-empty and unique.');
            }
            if ($need === '' || $reason === '' || $requestedBy === []) {
                throw new RuntimeException('Design System Gap '.$id.' requires need, reason and requested_by.');
            }
            if (!in_array($decision, self::DECISIONS, true)) {
                throw new RuntimeException('Unsupported Design System Gap decision: '.$decision);
            }
            if ($decision !== 'PENDING') {
                if (trim((string) ($item['decided_by'] ?? '')) === '' || trim((string) ($item['evidence'] ?? '')) === '') {
                    throw new RuntimeException('Resolved Design System Gap '.$id.' requires decided_by and evidence.');
                }
            }
            $seen[$id] = true;
            $item['decision'] = $decision;
            $item['requested_by'] = $requestedBy;
            $result[] = $item;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function pending(): array
    {
        return array_values(array_filter($this->all(), static fn (array $item): bool => $item['decision'] === 'PENDING'));
    }

    /** @return list<array<string,mixed>> */
    public function forPage(PageContract $page): array
    {
        $pageId = $page->id->value;
        return array_values(array_filter($this->all(), static fn (array $item): bool =>
            in_array($pageId, $item['requested_by'], true)
        ));
    }

    /** @return array<string,mixed> */
    private function document(): array
    {
        if (!is_file($this->manifest)) {
            throw new RuntimeException('Design System Gap registry is missing.');
        }
        $document = Yaml::parseFile($this->manifest);
        if (!is_array($document)) {
            throw new RuntimeException('Design System Gap registry is invalid.');
        }
        return $document;
    }
}
