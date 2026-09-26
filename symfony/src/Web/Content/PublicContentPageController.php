<?php

declare(strict_types=1);

namespace App\Web\Content;

use App\Application\Content\Query\GetPublicArticleQuery;
use App\Application\Content\Query\GetPublicBlogQuery;
use App\Application\Content\Query\GetPublicGuideQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicContentPageController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private PagePresentationFactory $pages,
        private PublicContentPresenter $presenter,
    ) {
    }

    public function blog(Request $request): Response
    {
        try {
            $blog = $this->presenter->blog($this->queries->ask(
                new GetPublicBlogQuery(max(1, $request->query->getInt('page', 1))),
            ));
        } catch (Throwable $error) {
            error_log('public.content.blog_failed ' . $error->getMessage());

            return new Response('Blog is temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->render($request, 'experience/public/blog.html.twig', [
            'page' => $this->pages->create(
                PageArchetype::PublicCatalog,
                ['PageHeader', 'EntityList', 'Pagination', 'EmptyState'],
                $blog->state(),
            ),
            'blog' => $blog,
        ]);
    }

    public function article(Request $request, string $slug): Response
    {
        try {
            $data = $this->queries->ask(new GetPublicArticleQuery($slug));
        } catch (Throwable $error) {
            error_log('public.content.article_failed ' . $error->getMessage());

            return new Response('Article is temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!is_array($data)) {
            return new Response('Article was not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->render($request, 'experience/public/article.html.twig', [
            'page' => $this->pages->create(PageArchetype::PublicDetailMarketing, ['PageHeader', 'ActionBar']),
            'content' => $this->presenter->article($data, $request->getSchemeAndHttpHost()),
        ]);
    }

    public function guide(Request $request, string $slug): Response
    {
        try {
            $landing = $this->queries->ask(new GetPublicGuideQuery($slug));
        } catch (Throwable $error) {
            error_log('public.content.guide_failed ' . $error->getMessage());

            return new Response('Guide is temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!is_array($landing)) {
            return new Response('Guide was not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->render($request, 'experience/public/guide.html.twig', [
            'page' => $this->pages->create(PageArchetype::PublicDetailMarketing, ['PageHeader', 'ActionBar']),
            'content' => $this->presenter->guide($landing, $request->getSchemeAndHttpHost()),
        ]);
    }

    /** @param array<string,mixed> $variables */
    private function render(Request $request, string $template, array $variables): Response
    {
        return new Response(
            $this->twig->render($template, $variables),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => $request->isMethod('GET') ? 'public, max-age=60' : 'no-store, private',
            ],
        );
    }
}
