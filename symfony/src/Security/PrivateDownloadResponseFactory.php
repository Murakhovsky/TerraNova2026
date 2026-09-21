<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final readonly class PrivateDownloadResponseFactory
{
    public function create(
        string $contents,
        string $filename,
        string $contentType = 'application/octet-stream',
    ): Response {
        $filename = $this->filename($filename);

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => trim($contentType) !== '' ? trim($contentType) : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                'download',
            ),
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        return $filename !== '' ? mb_substr($filename, 0, 191) : 'download';
    }
}
