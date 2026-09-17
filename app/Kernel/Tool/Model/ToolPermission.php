<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ToolPermission extends ValueObject
{
    public function __construct(private string $name)
    {
        $name = strtolower(trim($name));
        if ($name === '' || strlen($name) > 190 || preg_match('/^[a-z0-9._:-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Tool permission requires a valid canonical name.');
        }
        $this->name = $name;
    }

    public static function execute(string $toolName): self
    {
        return new self('tool.' . strtolower(trim($toolName)) . '.execute');
    }

    public function name(): string
    {
        return $this->name;
    }
}
