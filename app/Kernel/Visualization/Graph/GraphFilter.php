<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

use InvalidArgumentException;

final readonly class GraphFilter
{
    /** @param list<string> $nodeTypes @param list<string> $relations */
    public function __construct(
        public array $nodeTypes = [],
        public array $relations = [],
    ) {
        $this->assertVocabulary($this->nodeTypes, 'node type');
        $this->assertVocabulary($this->relations, 'relation');
    }

    /** @param list<string> $values */
    private function assertVocabulary(array $values, string $kind): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException(sprintf('Graph filter contains duplicate %ss.', $kind));
        }
        foreach ($values as $value) {
            if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_.:-]*$/', $value)) {
                throw new InvalidArgumentException(sprintf('Invalid graph filter %s: %s.', $kind, (string) $value));
            }
        }
    }
}
