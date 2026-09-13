<?php
declare(strict_types=1);

namespace Kernel\Action\Service;

use InvalidArgumentException;
use Kernel\Action\Contract\ActionHandlerInterface;
use RuntimeException;

final class ActionHandlerRegistry
{
    /** @var array<string, ActionHandlerInterface> */
    private array $handlersByType = [];

    /** @var list<ActionHandlerInterface> */
    private array $fallbackHandlers = [];

    /**
     * Accepts either a precomputed actionType => handler map or a legacy list of handlers.
     * The map form is the canonical runtime path; the list form preserves compatibility for isolated callers/tests.
     *
     * @param array<string, ActionHandlerInterface>|list<ActionHandlerInterface> $handlers
     */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $type => $handler) {
            if (!$handler instanceof ActionHandlerInterface) {
                throw new InvalidArgumentException('Action handler registry accepts ActionHandlerInterface instances only.');
            }

            if (is_string($type)) {
                if ($type === '') {
                    throw new InvalidArgumentException('Action handler type cannot be empty.');
                }
                $this->handlersByType[$type] = $handler;
                continue;
            }

            $this->fallbackHandlers[] = $handler;
        }
    }

    public function handlerFor(string $type): ActionHandlerInterface
    {
        if (isset($this->handlersByType[$type])) {
            return $this->handlersByType[$type];
        }

        $match = null;
        foreach ($this->fallbackHandlers as $handler) {
            if (!$handler->supports($type)) {
                continue;
            }
            if ($match !== null) {
                throw new RuntimeException(sprintf('Multiple action handlers registered for %s.', $type));
            }
            $match = $handler;
        }

        if ($match === null) {
            throw new RuntimeException(sprintf('No action handler registered for %s.', $type));
        }

        return $this->handlersByType[$type] = $match;
    }
}
