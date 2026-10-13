<?php
declare(strict_types=1);

namespace App\Web\Experience\Governance;

use App\Web\Experience\Registry\PageContract;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class ProductUxContextProvider
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/../resources/experience/personas.yaml')] private string $personasManifest,
        #[Autowire('%kernel.project_dir%/../resources/experience/journeys.yaml')] private string $journeysManifest,
        #[Autowire('%kernel.project_dir%/../resources/experience/terminology.yaml')] private string $terminologyManifest,
    ) {}

    /** @return array<string,mixed> */
    public function forPage(PageContract $page): array
    {
        $personasDoc = $this->document($this->personasManifest);
        $journeysDoc = $this->document($this->journeysManifest);
        $terminologyDoc = $this->document($this->terminologyManifest);

        $allPersonas = is_array($personasDoc['personas'] ?? null) ? $personasDoc['personas'] : [];
        $personas = [];
        foreach ($page->personas as $personaId) {
            if (isset($allPersonas[$personaId]) && is_array($allPersonas[$personaId])) {
                $personas[$personaId] = $allPersonas[$personaId];
            }
        }

        $journeys = [];
        foreach (is_array($journeysDoc['journeys'] ?? null) ? $journeysDoc['journeys'] : [] as $journey) {
            if (!is_array($journey)) {
                continue;
            }
            $pages = is_array($journey['pages'] ?? null) ? array_map('strval', $journey['pages']) : [];
            if (in_array($page->id->value, $pages, true)) {
                $journeys[] = $journey;
            }
        }

        $terms = [];
        foreach (is_array($terminologyDoc['terms'] ?? null) ? $terminologyDoc['terms'] : [] as $term) {
            if (!is_array($term)) {
                continue;
            }
            $scope = strtolower(trim((string) ($term['scope'] ?? '')));
            $domain = strtolower(trim($page->domain));
            if ($scope === 'cross-domain' || $scope === $domain || str_replace(' ', '', $scope) === str_replace(' ', '', $domain)) {
                $terms[] = $term;
            }
        }

        return [
            'personas' => $personas,
            'journeys' => $journeys,
            'terminology' => $terms,
        ];
    }

    /** @return array<string,mixed> */
    private function document(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Product UX context manifest missing: '.$path);
        }
        $document = Yaml::parseFile($path);
        if (!is_array($document)) {
            throw new RuntimeException('Product UX context manifest invalid: '.$path);
        }
        return $document;
    }
}
