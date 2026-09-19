<?php
declare(strict_types=1);

namespace App\Web\Phtml;

final class UrlHelper
{
    public function get(string $path = ''): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if (
            str_starts_with($path, '#')
            || str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_starts_with($path, 'tel:')
            || str_starts_with($path, 'mailto:')
        ) {
            return $path;
        }

        return '/' . ltrim($path, '/');
    }
}
