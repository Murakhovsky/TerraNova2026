<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Throwable;

class IndexController extends ControllerBase
{
    public function indexAction(): void
    {
        $this->view->pageStyles = ['css/terranova-home.css?v=20260719-home9'];
        $this->view->pageScripts = ['js/terranova-home.js?v=20260718-api3'];
        $this->view->featuredProperties = [];
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->inboundRequestStatus = null;
        $this->view->catalogStatus = null;

        try {
            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            }

            $this->view->types = [];
            $this->view->locations = [];
        } catch (Throwable $e) {
            $this->logFrontendError('home-page', $e);
            $this->view->catalogStatus = 'РљР°С‚Р°Р»РѕРі С‚РёРјС‡Р°СЃРѕРІРѕ РЅРµРґРѕСЃС‚СѓРїРЅРёР№. РџСѓР±Р»С–С‡РЅР° СЃС‚РѕСЂС–РЅРєР° РїСЂР°С†СЋС”, Р° РѕР±вЂ™С”РєС‚Рё РїС–РґС‚СЏРіРЅСѓС‚СЊСЃСЏ РїС–СЃР»СЏ РІС–РґРЅРѕРІР»РµРЅРЅСЏ Р·вЂ™С”РґРЅР°РЅРЅСЏ Р· Р‘Р”.';

            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = 'Р—Р°СЏРІРєСѓ РЅРµ РІРґР°Р»РѕСЃСЏ Р·Р±РµСЂРµРіС‚Рё. РЎРїСЂРѕР±СѓР№С‚Рµ С‰Рµ СЂР°Р· Р°Р±Рѕ РЅР°РїРёС€С–С‚СЊ РЅР°Рј РЅР°РїСЂСЏРјСѓ.';
            }
        }
    }
}
