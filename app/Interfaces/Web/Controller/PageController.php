<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Throwable;

class PageController extends ControllerBase
{
    public function showAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('slug');
        $page = $this->publicPageService()->page($slug);

        if (!$page) {
            $this->response->setStatusCode(404, 'Not Found');
            return;
        }

        $this->view->page = $page;
        $this->view->inboundRequestStatus = null;
        $this->view->metaTitle = $page['title'] . ' | Terra Nova CLUB';
        $this->view->metaDescription = $page['description'];
        $this->view->metaUrl = $this->pageAbsoluteUrl((string) $page['path']);

        if ($this->request->isPost() && !empty($page['has_form'])) {
            try {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            } catch (Throwable $e) {
                $this->logFrontendError('public-page-request', $e);
                $this->view->inboundRequestStatus = 'Заявку не вдалося зберегти. Спробуйте ще раз або зв’яжіться з нами напряму.';
            }
        }
    }

    private function pageAbsoluteUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8001';

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}

