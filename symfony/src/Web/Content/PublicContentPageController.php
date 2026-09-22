<?php
declare(strict_types=1);

namespace App\Web\Content;

use App\Web\Phtml\PhtmlRenderer;
use Domains\Content\Application\Contract\ContentServiceInterface;
use Domains\Content\Application\Service\PublicPageCatalog;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class PublicContentPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private ContentServiceInterface $content,
        private PublicPageCatalog $pages,
        private SalesWriteServiceFactoryInterface $writes,
        private string $organizationId,
    ) {
    }

    public function page(Request $request, string $slug): Response
    {
        $page = $this->pages->page($slug);
        if ($page === null) {
            return new Response('Page was not found.', Response::HTTP_NOT_FOUND);
        }

        $inboundRequestStatus = null;
        if ($request->isMethod('POST')) {
            if (empty($page['has_form'])) {
                return new Response('Method Not Allowed', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'GET, HEAD']);
            }

            try {
                $result = $this->writes
                    ->forOrganization($this->organizationId)
                    ->receivePublicLead($request->request->all(), $request->getRequestUri());

                $inboundRequestStatus = $result->ok
                    ? 'Заявку прийнято. Менеджер зв’яжеться з вами.'
                    : match ($result->code) {
                        'contact_required' => 'Вкажіть ім’я та телефон або email.',
                        'invalid_email' => 'Перевірте email.',
                        default => 'Заявку не вдалося зберегти.',
                    };
            } catch (\Throwable $error) {
                error_log('public.page.lead_failed ' . $error->getMessage());
                $inboundRequestStatus = 'Заявку не вдалося зберегти.';
            }
        }

        return $this->html($request, 'page/show', [
            'interfaceSurface' => 'public',
            'pageAssetEntries' => ['public-surface'],
            'page' => $page,
            'inboundRequestStatus' => $inboundRequestStatus,
            'metaTitle' => (string) ($page['title'] ?? 'Terra Nova CLUB') . ' | Terra Nova CLUB',
            'metaDescription' => (string) ($page['description'] ?? ''),
            'metaUrl' => $request->getSchemeAndHttpHost() . '/' . rawurlencode($slug),
        ]);
    }

    public function blog(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));

        return $this->html($request, 'blog/index', [
            'interfaceSurface' => 'public',
            'blog' => $this->content->publicPosts($page),
            'metaTitle' => 'Блог про нерухомість | Terra Nova CLUB',
            'metaDescription' => 'Практичні матеріали Terra Nova про купівлю, продаж, підготовку та управління нерухомістю.',
            'metaUrl' => $request->getSchemeAndHttpHost() . '/blog' . ($page > 1 ? '?page=' . $page : ''),
        ]);
    }

    public function article(Request $request, string $slug): Response
    {
        $article = $this->content->publicPost($slug);
        if ($article === null) {
            return new Response('Article was not found.', Response::HTTP_NOT_FOUND);
        }

        $canonical = trim((string) ($article['canonical_url'] ?? ''));
        if ($canonical === '') {
            $canonical = $request->getSchemeAndHttpHost() . '/blog/' . rawurlencode((string) $article['slug']);
        }

        return $this->html($request, 'blog/show', [
            'interfaceSurface' => 'public',
            'article' => $article,
            'related' => $this->content->relatedPosts((int) $article['id']),
            'metaTitle' => (string) (($article['meta_title'] ?? '') ?: $article['title'] . ' | Terra Nova CLUB'),
            'metaDescription' => (string) (($article['meta_description'] ?? '') ?: ($article['excerpt'] ?? '')),
            'metaImage' => (string) (($article['og_image_url'] ?? '') ?: ($article['featured_image_url'] ?? '')),
            'metaUrl' => $canonical,
            'metaType' => 'article',
            'metaRobots' => (string) (($article['robots'] ?? '') ?: 'index,follow'),
        ]);
    }

    public function guide(Request $request, string $slug): Response
    {
        $landing = $this->content->publicLanding($slug);
        if ($landing === null) {
            return new Response('Guide was not found.', Response::HTTP_NOT_FOUND);
        }

        $canonical = trim((string) ($landing['canonical_url'] ?? ''));
        if ($canonical === '') {
            $canonical = $request->getSchemeAndHttpHost() . '/guide/' . rawurlencode((string) $landing['slug']);
        }

        return $this->html($request, 'blog/landing', [
            'interfaceSurface' => 'public',
            'landing' => $landing,
            'metaTitle' => (string) (($landing['meta_title'] ?? '') ?: $landing['title'] . ' | Terra Nova CLUB'),
            'metaDescription' => (string) (($landing['meta_description'] ?? '') ?: ($landing['excerpt'] ?? '')),
            'metaImage' => (string) (($landing['og_image_url'] ?? '') ?: ($landing['featured_image_url'] ?? '')),
            'metaUrl' => $canonical,
            'metaRobots' => (string) (($landing['robots'] ?? '') ?: 'index,follow'),
        ]);
    }

    /** @param array<string,mixed> $variables */
    private function html(Request $request, string $view, array $variables): Response
    {
        return new Response(
            $this->renderer->render($request, $view, $variables),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
