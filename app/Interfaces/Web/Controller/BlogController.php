<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

class BlogController extends ControllerBase
{
    public function indexAction(): void
    {
        $page = max(1, (int) $this->request->getQuery('page', 'int', 1));
        $this->view->blog = $this->contentService()->publicPosts($page);
        $this->view->metaTitle = 'Блог про нерухомість | Terra Nova CLUB';
        $this->view->metaDescription = 'Практичні матеріали Terra Nova про купівлю, продаж, підготовку та управління нерухомістю.';
        $this->view->metaUrl = $this->absoluteUrl('blog' . ($page > 1 ? '?page=' . $page : ''));
    }

    public function showAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('slug');
        $article = $this->contentService()->publicPost($slug);
        if (!$article) {
            $this->response->setStatusCode(404, 'Not Found');
            return;
        }
        $this->view->article = $article;
        $this->view->related = $this->contentService()->relatedPosts((int) $article['id']);
        $this->view->metaTitle = (string) ($article['meta_title'] ?: $article['title'] . ' | Terra Nova CLUB');
        $this->view->metaDescription = (string) ($article['meta_description'] ?: $article['excerpt']);
        $this->view->metaImage = (string) ($article['og_image_url'] ?: $article['featured_image_url']);
        $this->view->metaUrl = (string) ($article['canonical_url'] ?: $this->absoluteUrl('blog/' . $article['slug']));
        $this->view->metaType = 'article';
        $this->view->metaRobots = (string) $article['robots'];
    }

    public function landingAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('slug');
        $landing = $this->contentService()->publicLanding($slug);
        if (!$landing) {
            $this->response->setStatusCode(404, 'Not Found');
            return;
        }
        $this->view->landing = $landing;
        $this->view->metaTitle = (string) ($landing['meta_title'] ?: $landing['title'] . ' | Terra Nova CLUB');
        $this->view->metaDescription = (string) ($landing['meta_description'] ?: $landing['excerpt']);
        $this->view->metaImage = (string) ($landing['og_image_url'] ?: $landing['featured_image_url']);
        $this->view->metaUrl = (string) ($landing['canonical_url'] ?: $this->absoluteUrl('guide/' . $landing['slug']));
        $this->view->metaRobots = (string) $landing['robots'];
        $this->view->pick('blog/landing');
    }

    private function absoluteUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8001';
        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}

