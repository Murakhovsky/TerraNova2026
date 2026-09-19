<?php
declare(strict_types=1);

namespace App\Web\Phtml;

use Symfony\Component\HttpFoundation\Request;

final readonly class RequestQueryAdapter
{
    public function __construct(private Request $request)
    {
    }

    public function getQuery(?string $name = null, ?string $filter = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->request->query->all();
        }

        $value = $this->request->query->get($name, $default);
        if ($filter === 'int') {
            return is_numeric($value) ? (int) $value : (int) $default;
        }
        if ($filter === 'string') {
            return is_scalar($value) ? trim((string) $value) : (string) $default;
        }

        return $value;
    }

    public function uri(): string
    {
        return $this->request->getRequestUri();
    }
}
