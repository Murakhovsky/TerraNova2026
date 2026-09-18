<?php
declare(strict_types=1);
namespace App\Application\Diagnostic\Command;
use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;
final readonly class CompleteDiagnosticCommand implements CommandInterface
{
    public function __construct(public OrganizationId $organizationId,public int $actorId,public string $sessionId,public string $correlationId){}
}
