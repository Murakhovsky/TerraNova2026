<?php
declare(strict_types=1);

namespace Kernel\Queue\Service;

use InvalidArgumentException;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Queue\Contract\JobHandlerInterface;

final class JobHandlerRegistry
{
    /** @var list<JobHandlerInterface> */
    private array $handlers = [];

    /** @var array<string, JobHandlerInterface> */
    private array $handlersByType = [];

    /** @param iterable<JobHandlerInterface> $handlers */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            if (!$handler instanceof JobHandlerInterface) {
                throw new InvalidArgumentException('Job handler registry accepts JobHandlerInterface instances only.');
            }
            $this->handlers[] = $handler;
        }
    }

    public function handlerFor(string $type): JobHandlerInterface
    {
        if ($type === '') {
            throw new InvalidArgumentException('Job type cannot be empty.');
        }
        if (isset($this->handlersByType[$type])) {
            return $this->handlersByType[$type];
        }

        $match = null;
        foreach ($this->handlers as $handler) {
            if (!$handler->supports($type)) {
                continue;
            }
            if ($match !== null) {
                throw ExecutionFailureException::permanent(sprintf(
                    'Multiple job handlers registered for %s.',
                    $type,
                ));
            }
            $match = $handler;
        }

        if ($match === null) {
            throw ExecutionFailureException::permanent(sprintf(
                'No handler for job type %s.',
                $type,
            ));
        }

        return $this->handlersByType[$type] = $match;
    }
}
