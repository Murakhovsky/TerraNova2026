<?php
declare(strict_types=1);

namespace Infrastructure\Web\Assets;

use RuntimeException;

final class ViteAssetResolver
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $manifest = null;

    /** @param list<string> $entries @return array{scripts:list<string>,styles:list<string>} */
    public static function resolve(array $entries): array
    {
        $manifest = self::manifest();
        $scripts = [];
        $styles = [];

        foreach (array_values(array_unique($entries)) as $entry) {
            $asset = null;
            foreach ([
                'frontend/entrypoints/' . $entry . '.js',
                'frontend/spatial/' . $entry . '.js',
            ] as $source) {
                if (isset($manifest[$source]) && is_array($manifest[$source])) {
                    $asset = $manifest[$source];
                    break;
                }
            }

            if (!is_array($asset)) {
                throw new RuntimeException(sprintf('Vite entrypoint "%s" is absent from the manifest.', $entry));
            }

            $scripts[] = self::url((string) $asset['file']);
            foreach ((array) ($asset['css'] ?? []) as $css) {
                $styles[] = self::url((string) $css);
            }
        }

        return [
            'scripts' => array_values(array_unique($scripts)),
            'styles' => array_values(array_unique($styles)),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $root = dirname(__DIR__, 4);
        $candidates = [
            $root . '/public/build/.vite/manifest.json',
            $root . '/symfony/public/build/.vite/manifest.json',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Vite manifest must contain a JSON object.');
            }

            /** @var array<string,array<string,mixed>> $decoded */
            return self::$manifest = $decoded;
        }

        throw new RuntimeException('Vite manifest is missing. Run npm run build.');
    }

    private static function url(string $path): string
    {
        return '/build/' . ltrim($path, '/');
    }
}
