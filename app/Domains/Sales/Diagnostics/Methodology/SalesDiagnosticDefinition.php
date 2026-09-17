<?php
declare(strict_types=1);

namespace Domains\Sales\Diagnostics\Methodology;

use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use InvalidArgumentException;

final readonly class SalesDiagnosticDefinition
{
    public function __construct(public MethodologyPack $pack)
    {
        if (trim($pack->id) === '' || trim($pack->name) === '' || $pack->version < 1) {
            throw new InvalidArgumentException('Sales diagnostic methodology must have a valid id, name and version.');
        }
        if ($pack->criteria === []) {
            throw new InvalidArgumentException('Sales diagnostic methodology requires at least one criterion.');
        }
    }

    public function id(): string
    {
        return $this->pack->id;
    }

    public function version(): int
    {
        return $this->pack->version;
    }
}
