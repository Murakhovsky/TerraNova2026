<?php
declare(strict_types=1);

namespace Interfaces\Web\Assets;

final class ViewAssetResolver
{
    /** @param list<string> $entries @return array{scripts:list<string>,styles:list<string>} */
    public static function resolve(array $entries): array
    {
        static $manifest = null;

        if (!$manifest instanceof ViteAssetManifest) {
            $manifest = new ViteAssetManifest(
                dirname(__DIR__, 4) . '/public/build/.vite/manifest.json',
            );
        }

        return $manifest->assets($entries);
    }
}
