<?php
declare(strict_types=1);

namespace App\Web\Experience\Model;

use InvalidArgumentException;

final readonly class EntityRef
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (!preg_match('/^[a-z][a-z0-9._-]*$/', $this->type)) {
            throw new InvalidArgumentException('EntityRef type must be a stable lowercase identifier.');
        }

        if ($this->id === '' || trim($this->id) !== $this->id) {
            throw new InvalidArgumentException('EntityRef id must be a non-empty canonical identifier.');
        }
    }

    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    public static function fromKey(string $key): self
    {
        $parts = explode(':', $key, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('EntityRef key must use type:id format.');
        }

        return new self($parts[0], $parts[1]);
    }
}
