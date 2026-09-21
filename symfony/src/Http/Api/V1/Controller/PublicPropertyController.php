<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Property\Service\PublicPropertyReadService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class PublicPropertyController
{
    public function __construct(private PublicPropertyReadService $properties)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->properties->catalog($request->query->all()));
        } catch (Throwable) {
            return $this->error(503, 'property_catalog_unavailable', 'Каталог тимчасово недоступний.');
        }
    }

    public function featured(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->properties->featured((int) $request->query->get('limit', 4)));
        } catch (Throwable) {
            return $this->error(503, 'property_featured_unavailable', 'Об’єкти тимчасово недоступні.');
        }
    }

    public function favourites(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $key = 'cos_public_property_favourites';
        $items = array_values(array_filter(
            $session->get($key, []),
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        if ($request->isMethod('POST')) {
            $publicId = trim((string) $request->request->get('public_id', ''));
            if ($publicId === '' || strlen($publicId) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $publicId) !== 1) {
                return $this->error(422, 'invalid_property_public_id', 'Некоректний ідентифікатор об’єкта.');
            }

            $index = array_search($publicId, $items, true);
            if ($index === false) {
                if (count($items) < 100) {
                    $items[] = $publicId;
                }
            } else {
                unset($items[$index]);
                $items = array_values($items);
            }

            $session->set($key, $items);
        }

        return $this->ok(['items' => $items]);
    }

    public function show(string $slug): JsonResponse
    {
        try {
            $data = $this->properties->show($slug);
            return $data === null
                ? $this->error(404, 'property_not_found', 'Об’єкт не знайдено.')
                : $this->ok($data);
        } catch (Throwable) {
            return $this->error(503, 'property_read_unavailable', 'Сторінка об’єкта тимчасово недоступна.');
        }
    }

    private function ok(array $data): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'data' => $data]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
