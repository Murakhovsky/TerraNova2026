<?php
declare(strict_types=1);
namespace App\Application\Operations\Command;
use Kernel\Application\Command\CommandInterface;
final readonly class OperationsMutationCommand implements CommandInterface
{
 public const EXECUTE_ACTION='execute_action';public const DISMISS_ACTION='dismiss_action';public const APPROVE='approve';public const REJECT='reject';
 public function __construct(public string $organizationId,public int $actorId,public string $operation,public string $resourceId,public array $input,public string $correlationId){}
}
