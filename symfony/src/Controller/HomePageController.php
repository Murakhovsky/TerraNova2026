<?php
declare(strict_types=1);

namespace App\Controller;

use App\Web\Phtml\PhtmlRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class HomePageController
{
    public function __construct(private PhtmlRenderer $renderer) {}

    public function __invoke(Request $request): Response
    {
        return new Response(
            $this->renderer->render($request, 'home/canonical', [
                'title' => 'Terra Nova COS',
                'metaTitle' => 'Terra Nova COS | Company Operating System',
                'metaDescription' => 'Canonical Symfony runtime for Terra Nova Company Operating System.',
                'metaRobots' => 'index,follow',
                'metaUrl' => $request->getSchemeAndHttpHost() . '/',
                'interfaceSurface' => 'public',
                'pageAssetEntries' => ['public-surface'],
                'currentUser' => null,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
