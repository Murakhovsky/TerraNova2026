<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

class ApiController extends ControllerBase
{
    private const FAVOURITES_SESSION_KEY = 'frontend.favourites';
    private const FAVOURITES_LIMIT = 100;

    public function favouritesAction(): \Phalcon\Http\ResponseInterface
    {
        $this->view->disable();
        $items = $this->favourites();

        if ($this->request->isGet()) {
            return $this->json([
                'ok' => true,
                'items' => $items,
                'count' => count($items),
            ]);
        }

        if (!$this->request->isPost()) {
            return $this->json([
                'ok' => false,
                'error' => 'method_not_allowed',
                'message' => 'Метод не підтримується.',
            ], 405);
        }

        $publicId = trim((string) $this->request->getPost('public_id', 'string', ''));
        if ($publicId === '' || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $publicId) !== 1) {
            return $this->json([
                'ok' => false,
                'error' => 'validation_failed',
                'message' => 'Некоректний ідентифікатор об’єкта.',
            ], 422);
        }

        $saved = !in_array($publicId, $items, true);
        if ($saved) {
            if (count($items) >= self::FAVOURITES_LIMIT) {
                return $this->json([
                    'ok' => false,
                    'error' => 'limit_reached',
                    'message' => 'Досягнуто ліміт вибраних об’єктів.',
                ], 422);
            }
            $items[] = $publicId;
        } else {
            $items = array_values(array_filter(
                $items,
                static fn (string $item): bool => $item !== $publicId,
            ));
        }

        $this->di->getShared('session')->set(self::FAVOURITES_SESSION_KEY, $items);

        return $this->json([
            'ok' => true,
            'items' => $items,
            'count' => count($items),
            'saved' => $saved,
        ]);
    }

    /** @return list<string> */
    private function favourites(): array
    {
        $raw = $this->di->getShared('session')->get(self::FAVOURITES_SESSION_KEY, []);
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $value) {
            $id = trim((string) $value);
            if ($id === '' || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $id) !== 1) {
                continue;
            }

            $items[$id] = $id;
            if (count($items) >= self::FAVOURITES_LIMIT) {
                break;
            }
        }

        return array_values($items);
    }
}
