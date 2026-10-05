<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Currency;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class InstrumentDescriptor extends ValueObject
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public InstrumentId $id,
        public string $symbol,
        public string $canonicalSymbol,
        public string $name,
        public InstrumentFamily $family,
        public InstrumentStatus $status,
        public ?Currency $currency,
        public ?AssetCode $quoteAsset,
        public ?string $issuerReference,
        public ?string $jurisdiction,
        public ?string $primaryVenueReference,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        foreach (['symbol'=>$this->symbol,'canonical symbol'=>$this->canonicalSymbol] as $label=>$value) {
            if ($value==='' || trim($value)!==$value || mb_strlen($value)>64 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/\-]*$/',$value)!==1) {
                throw new InvalidArgumentException('Instrument '.$label.' must be a stable trimmed market symbol up to 64 characters.');
            }
        }
        if ($this->name==='' || trim($this->name)!==$this->name || mb_strlen($this->name)>190) {
            throw new InvalidArgumentException('Instrument name must be a trimmed non-empty value up to 190 characters.');
        }
        foreach ([$this->issuerReference,$this->jurisdiction,$this->primaryVenueReference] as $reference) {
            if ($reference!==null && ($reference==='' || trim($reference)!==$reference || mb_strlen($reference)>190)) {
                throw new InvalidArgumentException('Instrument optional references must be null or trimmed values up to 190 characters.');
            }
        }
        if ($this->updatedAt < $this->createdAt) throw new InvalidArgumentException('Instrument updated_at cannot precede created_at.');
        self::assertMetadata($this->metadata);
    }

    public function isLiveEligible(): bool { return $this->status->canBeLiveExecuted(); }

    /** @param array<string,mixed> $metadata */
    public static function assertMetadata(array $metadata): void
    {
        $encoded=json_encode($metadata,JSON_THROW_ON_ERROR);
        if (strlen($encoded)>16384) throw new InvalidArgumentException('Instrument metadata cannot exceed 16 KiB.');
    }
}
