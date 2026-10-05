<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class InstrumentDescriptor extends ValueObject
{
    /**
     * @param array<string,string> $externalIdentifiers
     */
    public function __construct(
        public InstrumentId $id,
        public InstrumentFamily $family,
        public string $symbol,
        public string $name,
        public array $externalIdentifiers = [],
    ) {
        if ($this->symbol === '' || trim($this->symbol) !== $this->symbol || strlen($this->symbol) > 64) {
            throw new InvalidArgumentException('Instrument symbol must be a trimmed non-empty value up to 64 characters.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/\-]*$/', $this->symbol) !== 1) {
            throw new InvalidArgumentException('Instrument symbol contains unsupported characters.');
        }
        if ($this->name === '' || trim($this->name) !== $this->name || strlen($this->name) > 190) {
            throw new InvalidArgumentException('Instrument name must be a trimmed non-empty value up to 190 characters.');
        }

        foreach ($this->externalIdentifiers as $namespace => $value) {
            if (
                !is_string($namespace)
                || preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $namespace) !== 1
                || !is_string($value)
                || $value === ''
                || trim($value) !== $value
                || strlen($value) > 190
            ) {
                throw new InvalidArgumentException('Instrument external identifiers must use stable namespaces and non-empty values.');
            }
        }
    }
}
