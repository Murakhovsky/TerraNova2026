<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Shared\Domain\ValueObject;

final readonly class ToolInvocation extends ValueObject
{
    private string $toolName;
    private string $correlationId;

    /** @param array<string, mixed> $input */
    public function __construct(
        private OrganizationId $organizationId,
        string $toolName,
        private array $input,
        string $correlationId,
        private ?UserId $requestedBy = null,
    ) {
        $toolName = strtolower(trim($toolName));
        if ($toolName === '' || strlen($toolName) > 190 || preg_match('/^[a-z0-9._:-]+$/', $toolName) !== 1) {
            throw new InvalidArgumentException('Tool invocation requires a valid tool name.');
        }

        $correlationId = trim($correlationId);
        if ($correlationId === '' || strlen($correlationId) > 190) {
            throw new InvalidArgumentException('Tool invocation requires a correlation id.');
        }

        $this->toolName = $toolName;
        $this->correlationId = $correlationId;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    public function toolName(): string
    {
        return $this->toolName;
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->input;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function requestedBy(): ?UserId
    {
        return $this->requestedBy;
    }
}
