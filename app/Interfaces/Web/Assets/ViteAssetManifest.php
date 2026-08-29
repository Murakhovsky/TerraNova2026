<?php
declare(strict_types=1);

namespace Interfaces\Web\Assets;

use RuntimeException;

final class ViteAssetManifest
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $publicPrefix = '/build/',
    ) {
    }

    /**
     * @param list<string> $entries
     * @return array{scripts: list<string>, styles: list<string>}
     */
    public function assets(array $entries): array
    {
        $scripts = [];
        $styles = [];

        foreach (array_values(array_unique($entries)) as $entry) {
            $asset = $this->entry($entry);
            $scripts[] = $this->url((string) $asset['file']);

            foreach ((array) ($asset['css'] ?? []) as $css) {
                $styles[] = $this->url((string) $css);
            }
        }

        return [
            'scripts' => array_values(array_unique($scripts)),
            'styles' => array_values(array_unique($styles)),
        ];
    }

    /** @return array<string, mixed> */
    private function entry(string $name): array
    {
        $manifest = $this->manifest();
        $sources = [
            'frontend/entrypoints/' . $name . '.js',
            'frontend/spatial/' . $name . '.js',
        ];

        foreach ($sources as $source) {
            if (isset($manifest[$source]) && is_array($manifest[$source])) {
                return $manifest[$source];
            }
        }

        throw new RuntimeException(sprintf('Vite entrypoint "%s" is absent from %s.', $name, $this->manifestPath));
    }

    /** @return array<string, array<string, mixed>> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!is_file($this->manifestPath)) {
            throw new RuntimeException('Vite manifest is missing. Run `npm run build`.');
        }

        $decoded = json_decode((string) file_get_contents($this->manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Vite manifest must contain a JSON object.');
        }

        /** @var array<string, array<string, mixed>> $decoded */
        return $this->manifest = $decoded;
    }

    private function url(string $path): string
    {
        return rtrim($this->publicPrefix, '/') . '/' . ltrim($path, '/');
    }
}
