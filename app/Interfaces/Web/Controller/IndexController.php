<?php

declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Throwable;

class IndexController extends ControllerBase
{
    public function indexAction(): void
    {
        $this->view->pick('index/public');
        $this->view->interfaceSurface = 'public';
        $this->view->pageAssetEntries = ['public-surface'];
        $this->view->featuredProperties = [];
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->inboundRequestStatus = null;
        $this->view->catalogStatus = null;
        $this->view->metaTitle = 'Terra Nova CLUB | Нерухомість у Львові та області';
        $this->view->metaDescription = 'Каталог об’єктів Terra Nova у Львові, Брюховичах, Ременові та інших локаціях: продаж, оренда, підбір і подача нерухомості.';
        $this->view->metaUrl = $this->homeAbsoluteUrl();

        try {
            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            }

            $this->view->featuredProperties = $this->catalogService()->featuredProperties(6);
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
        } catch (Throwable $e) {
            $this->logFrontendError('home-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->catalogStatus = 'Каталог тимчасово недоступний. Спробуйте оновити сторінку трохи пізніше.';

            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = 'Заявку не вдалося зберегти. Спробуйте ще раз або зв’яжіться з нами напряму.';
            }
        }
    }

    private function homeAbsoluteUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8001';

        return $scheme . '://' . $host . '/';
    }
}
