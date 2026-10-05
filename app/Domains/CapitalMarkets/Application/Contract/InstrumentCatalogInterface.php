<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;

interface InstrumentCatalogInterface
{
    public function get(InstrumentId $id): ?InstrumentDescriptor;

    /** @return list<InstrumentDescriptor> */
    public function all(): array;

    /** @return list<InstrumentDescriptor> */
    public function byFamily(InstrumentFamily $family): array;
}
