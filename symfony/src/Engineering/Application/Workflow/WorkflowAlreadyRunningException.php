<?php
declare(strict_types=1);

namespace App\Engineering\Application\Workflow;

use RuntimeException;

final class WorkflowAlreadyRunningException extends RuntimeException
{
}
