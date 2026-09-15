<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

use InvalidArgumentException;

final readonly class GraphView
{
    public GraphFilter $filters;

    public function __construct(
        public ?string $focus = null,
        public ?int $depth = null,
        public string $layout = 'auto',
        ?GraphFilter $filters = null,
    ) {
        if ($this->focus !== null && trim($this->focus) === '') {
            throw new InvalidArgumentException('Graph view focus cannot be empty.');
        }
        if ($this->depth !== null && $this->depth < 0) {
            throw new InvalidArgumentException('Graph view depth cannot be negative.');
        }
        if (trim($this->layout) === '') {
            throw new InvalidArgumentException('Graph view layout is required.');
        }
        $this->filters = $filters ?? new GraphFilter();
    }
}
