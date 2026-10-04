<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class RouteExemptionRegistry
{
    /** @var array<string,RouteExemption>|null */
    private ?array $exemptions = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/exemptions.yaml')]
        private readonly string $file,
    ) {
    }

    /** @return array<string,RouteExemption> */
    public function all(): array
    {
        if ($this->exemptions !== null) {
            return $this->exemptions;
        }

        if (!is_file($this->file)) {
            return $this->exemptions = [];
        }

        $document = Yaml::parseFile($this->file);
        if (!is_array($document)) {
            throw new RuntimeException('Invalid Experience route exemptions YAML.');
        }

        $items = is_array($document['exemptions'] ?? null) ? $document['exemptions'] : [];
        $indexed = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Experience route exemption must be a mapping.');
            }

            $exemption = RouteExemption::fromArray($item);
            if ($exemption->routeName === '' || $exemption->path === '' || $exemption->reason === '' || $exemption->description === '') {
                throw new InvalidArgumentException('Experience route exemption has empty required fields.');
            }
            if (isset($indexed[$exemption->routeName])) {
                throw new InvalidArgumentException('Duplicate Experience route exemption: ' . $exemption->routeName);
            }

            $indexed[$exemption->routeName] = $exemption;
        }

        ksort($indexed);

        return $this->exemptions = $indexed;
    }

    public function find(string $routeName): ?RouteExemption
    {
        return $this->all()[$routeName] ?? null;
    }
}
