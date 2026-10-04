<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use App\Web\Experience\Quality\PageQualityScore;

final readonly class PageContract
{
    /**
     * @param list<string> $methods
     * @param list<string> $personas
     * @param list<string> $requiredPatterns
     * @param list<string> $optionalPatterns
     * @param list<string> $states
     * @param array<string,string> $responsive
     * @param array<string,bool> $qa
     */
    public function __construct(
        public PageContractId $id,
        public string $routeName,
        public string $path,
        public array $methods,
        public string $surface,
        public string $domain,
        public string $capability,
        public string $owner,
        public string $priority,
        public PageExperienceStatus $status,
        public array $personas,
        public string $primaryGoal,
        public string $pagePurpose,
        public string $archetype,
        public array $requiredPatterns,
        public array $optionalPatterns,
        public ?string $primaryAction,
        public array $states,
        public array $responsive,
        public array $qa,
        public PageQualityScore $quality,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $route = is_array($data['route'] ?? null) ? $data['route'] : [];
        $product = is_array($data['product'] ?? null) ? $data['product'] : [];
        $patterns = is_array($data['patterns'] ?? null) ? $data['patterns'] : [];
        $responsive = is_array($data['responsive'] ?? null) ? $data['responsive'] : [];
        $qa = is_array($data['qa'] ?? null) ? $data['qa'] : [];

        return new self(
            id: new PageContractId((string) ($data['id'] ?? '')),
            routeName: (string) ($route['name'] ?? ''),
            path: (string) ($route['path'] ?? ''),
            methods: self::strings($route['methods'] ?? ['GET', 'HEAD']),
            surface: (string) ($data['surface'] ?? ''),
            domain: (string) ($data['domain'] ?? ''),
            capability: (string) ($data['capability'] ?? ''),
            owner: (string) ($data['owner'] ?? ''),
            priority: strtoupper((string) ($data['priority'] ?? 'P3')),
            status: PageExperienceStatus::from(strtoupper((string) ($data['status'] ?? 'CONTRACTED'))),
            personas: self::strings($data['personas'] ?? []),
            primaryGoal: (string) ($product['primary_goal'] ?? $data['primary_goal'] ?? ''),
            pagePurpose: (string) ($product['page_purpose'] ?? $data['page_purpose'] ?? ''),
            archetype: (string) ($data['archetype'] ?? ''),
            requiredPatterns: self::strings($patterns['required'] ?? []),
            optionalPatterns: self::strings($patterns['optional'] ?? []),
            primaryAction: isset($data['primary_action']) ? (string) $data['primary_action'] : null,
            states: self::strings($data['states'] ?? ['normal']),
            responsive: array_map('strval', $responsive),
            qa: array_map(static fn (mixed $value): bool => (bool) $value, $qa),
            quality: PageQualityScore::fromArray(is_array($data['quality'] ?? null) ? $data['quality'] : []),
        );
    }

    /** @return array<string,mixed> */
    public function toManifest(): array
    {
        return [
            'page' => $this->id->value,
            'route' => ['name' => $this->routeName, 'path' => $this->path, 'methods' => $this->methods],
            'surface' => $this->surface,
            'domain' => $this->domain,
            'capability' => $this->capability,
            'priority' => $this->priority,
            'status' => $this->status->value,
            'archetype' => $this->archetype,
            'patterns' => ['required' => $this->requiredPatterns, 'optional' => $this->optionalPatterns],
            'quality' => $this->quality->toArray(),
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
