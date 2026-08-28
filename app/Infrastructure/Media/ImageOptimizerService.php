<?php
declare(strict_types=1);

namespace Infrastructure\Media;

class ImageOptimizerService
{
    private const MAX_EDGE = 1920;
    private const JPEG_QUALITY = 82;
    private const WEBP_QUALITY = 82;
    private const PNG_COMPRESSION = 7;

    public function optimize(string $sourcePath, string $targetPath, string $mime): ImageOptimizationResult
    {
        if (!$this->canOptimize($mime)) {
            return new ImageOptimizationResult($targetPath, $mime, $this->extensionForMime($mime), false);
        }

        $size = @getimagesize($sourcePath);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return new ImageOptimizationResult($targetPath, $mime, $this->extensionForMime($mime), false);
        }

        $source = $this->createImage($sourcePath, $mime);
        if (!$source) {
            return new ImageOptimizationResult($targetPath, $mime, $this->extensionForMime($mime), false);
        }

        $width = (int) $size[0];
        $height = (int) $size[1];
        [$targetWidth, $targetHeight] = $this->targetSize($width, $height);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (!$canvas) {
            return new ImageOptimizationResult($targetPath, $mime, $this->extensionForMime($mime), false);
        }

        $this->prepareAlpha($canvas, $mime);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $outputMime = $this->outputMime($mime);
        $extension = $this->extensionForMime($outputMime);
        $optimizedPath = $this->pathWithExtension($targetPath, $extension);
        $saved = $this->saveImage($canvas, $optimizedPath, $outputMime);

        if (!$saved || !is_file($optimizedPath)) {
            return new ImageOptimizationResult($targetPath, $mime, $this->extensionForMime($mime), false);
        }

        return new ImageOptimizationResult($optimizedPath, $outputMime, $extension, true);
    }

    public function canOptimize(string $mime): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'image/png' => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default => false,
        };
    }

    private function createImage(string $path, string $mime): mixed
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
    }

    private function saveImage(mixed $image, string $path, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, self::JPEG_QUALITY),
            'image/png' => imagepng($image, $path, self::PNG_COMPRESSION),
            'image/webp' => imagewebp($image, $path, self::WEBP_QUALITY),
            default => false,
        };
    }

    private function targetSize(int $width, int $height): array
    {
        $maxEdge = max($width, $height);
        if ($maxEdge <= self::MAX_EDGE) {
            return [$width, $height];
        }

        $ratio = self::MAX_EDGE / $maxEdge;

        return [
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
        ];
    }

    private function prepareAlpha(mixed $image, string $mime): void
    {
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
    }

    private function outputMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            default => $mime,
        };
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    private function pathWithExtension(string $path, string $extension): string
    {
        return preg_replace('~\.[a-z0-9]+$~i', '.' . $extension, $path) ?: $path;
    }
}

final class ImageOptimizationResult
{
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        public readonly string $extension,
        public readonly bool $optimized
    ) {
    }
}
