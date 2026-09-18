<?php
declare(strict_types=1);
namespace App\Application\Diagnostic\Command;
use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;
final readonly class StartDiagnosticCommand implements CommandInterface
{
    public function __construct(public OrganizationId $organizationId,public int $actorId,public string $correlationId,public string $idempotencyKey,public array $input){}
}
