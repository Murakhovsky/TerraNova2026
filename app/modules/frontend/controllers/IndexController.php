<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Throwable;

class IndexController extends ControllerBase
{
    public function indexAction(): void
    {
        $this->view->featuredProperties = [];
        $this->view->leadStatus = null;
        $this->view->catalogStatus = null;

        try {
            $pdo = $this->getPdo();

            if ($this->request->isPost()) {
                $this->view->leadStatus = $this->storeLead($pdo);
            }

            $this->view->featuredProperties = $this->fetchFeaturedProperties($pdo, 3);
        } catch (Throwable $e) {
            $this->view->catalogStatus = 'Каталог тимчасово недоступний. Публічна сторінка працює, а об’єкти підтягнуться після відновлення з’єднання з БД.';

            if ($this->request->isPost()) {
                $this->view->leadStatus = 'Заявку не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';
            }
        }
    }
}
