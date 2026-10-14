<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Command\CreateInstrument;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsFoundationBoundary;
use Domains\CapitalMarkets\Domain\Contract\InstrumentRepository;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifierType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reuses the canonical audited instrument boundary to create only DRAFT records.
 * This is not a separate registry and does not establish economic relationships.
 */
final readonly class BStocksCanonicalDraftService
{
    public function __construct(
        private BStocksSeedImportRepository $seeds,
        private BStocksCanonicalDraftProjection $projection,
        private InstrumentRepository $instruments,
        private CapitalMarketsFoundationBoundary $foundation,
    ) {}

    /** @return array<string,mixed> */
    public function plan(string $org): array
    {
        if ($org==='') throw new InvalidArgumentException('Tenant required.');
        $seedRows=$this->seeds->listForCanonicalDraft($org);
        $out=['total'=>count($seedRows),'create'=>0,'existing'=>0,'blocked'=>0,'rows'=>[]];
        foreach ($seedRows as $evidence) {
            $input=$this->projection->createInput($org,$evidence);
            $token=$input['symbol'];
            $id=InstrumentId::fromString($input['id']);
            $identified=$this->instruments->findByIdentifier($org,
                new InstrumentIdentifier(InstrumentIdentifierType::ProviderId,$token,'BINANCE_BSTOCKS_SEED'));
            $byId=$this->instruments->get($org,$id);
            $ticker=$this->instruments->findByIdentifier($org,
                new InstrumentIdentifier(InstrumentIdentifierType::Ticker,$token));
            $status='CREATE_DRAFT';
            if ($identified!==null) {
                $status=$identified->id->equals($id) && $identified->symbol===$token
                    && $identified->family->value==='tokenized_security'
                    && (($identified->metadata['origin']??null)==='BINANCE_BSTOCKS_SEED')
                    && (($identified->metadata['underlying_reference_symbol']??null)===$input['metadata']['underlying_reference_symbol'])
                    ? 'EXISTING_SEEDED_INSTRUMENT' : 'BLOCKED_IDENTIFIER_COLLISION';
            } elseif ($byId!==null || $ticker!==null) {
                $status='BLOCKED_CANONICAL_IDENTITY_COLLISION';
            }
            $out['rows'][]=['token'=>$token,'instrument_id'=>$id->value(),'status'=>$status];
            if ($status==='CREATE_DRAFT') ++$out['create'];
            elseif ($status==='EXISTING_SEEDED_INSTRUMENT') ++$out['existing'];
            else ++$out['blocked'];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function apply(string $org,int $actor,int $limit=100): array
    {
        if ($actor<1 || $limit<1 || $limit>500) throw new InvalidArgumentException('Invalid draft apply request.');
        $preflight=$this->plan($org);
        if ($preflight['total']>$limit || $preflight['total']===0 || $preflight['blocked']>0) {
            throw new RuntimeException('Draft preflight blocked; no canonical instruments created.');
        }
        $seeds=$this->seeds->listForCanonicalDraft($org);
        $created=0;
        foreach ($seeds as $evidence) {
            $input=$this->projection->createInput($org,$evidence);
            $id=InstrumentId::fromString($input['id']);
            if ($this->instruments->get($org,$id)!==null) continue;
            $this->foundation->createInstrument(new CreateInstrument(
                $org,$actor,'cm-bstock-seed-'.substr(hash('sha256',$org.'|'.$input['symbol']),0,24),$input,
            ));
            ++$created;
        }
        return ['status'=>'DRAFT_ONLY','created'=>$created,'plan'=>$preflight,'economic_link_verified'=>false];
    }
}
