<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class SecureDownloadResponseFactory
{
    public function file(
        string $absolutePath,
        string $downloadName,
        string $contentType = 'application/octet-stream',
    ): BinaryFileResponse {
        $path = realpath($absolutePath);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Authorized download file is not readable.');
        }

        $safeName = trim(str_replace(["\r", "\n", '/', '\\'], '_', $downloadName));
        if ($safeName === '') {
            $safeName = 'download';
        }

        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $safeName);

        return $response;
    }
}
