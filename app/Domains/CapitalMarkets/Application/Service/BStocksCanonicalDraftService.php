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
    public function plan(string $org):array { return $this->planFor($org,false); }

    /** @return array<string,mixed> */
    public function planUnderlying(string $org):array { return $this->planFor($org,true); }

    /** @return array<string,mixed> */
    private function planFor(string $org,bool $underlying): array
    {
        if ($org==='') throw new InvalidArgumentException('Tenant required.');
        $seedRows=$this->seeds->listForCanonicalDraft($org);
        $out=['total'=>0,'create'=>0,'existing'=>0,'blocked'=>0,'rows'=>[]];
        $seen=[];
        foreach ($seedRows as $evidence) {
            $input=$underlying
                ?$this->projection->createUnderlyingInput($org,$evidence)
                :$this->projection->createInput($org,$evidence);
            if ($underlying && isset($seen[$input['symbol']])) {
                if ($seen[$input['symbol']]!==$input['family']) {
                    ++$out['blocked'];
                    $out['rows'][]=['token'=>$input['symbol'],'instrument_id'=>$input['id'],
                        'status'=>'BLOCKED_REFERENCE_TYPE_CONFLICT'];
                }
                continue;
            }
            $seen[$input['symbol']]=$input['family'];
            ++$out['total'];
            $token=$input['symbol'];
            $id=InstrumentId::fromString($input['id']);
            $source=$input['identifiers'][0]['source'];
            $identified=$this->instruments->findByIdentifier($org,
                new InstrumentIdentifier(InstrumentIdentifierType::ProviderId,$token,$source));
            $byId=$this->instruments->get($org,$id);
            $ticker=$this->instruments->findByIdentifier($org,
                new InstrumentIdentifier(InstrumentIdentifierType::Ticker,$token));
            $status='CREATE_DRAFT';
            if ($identified!==null) {
                $status=$identified->id->equals($id) && $identified->symbol===$token

                    && $identified->family->value===$input['family']
                    && (($identified->metadata['origin']??null)===$input['metadata']['origin'])
                    && (($identified->metadata['underlying_reference_symbol']??null)===$input['metadata']['underlying_reference_symbol'])
                    && (($identified->metadata['unverified_venue_symbol']??null)===($input['metadata']['unverified_venue_symbol']??null))
                    && (($identified->metadata['historical_asset_type']??null)===$input['metadata']['historical_asset_type'])
                    && (($identified->metadata['historical_leverage_factor']??null)===$input['metadata']['historical_leverage_factor'])
                    && (($identified->metadata['source_sha256']??null)===$input['metadata']['source_sha256'])
                    && (($identified->metadata['source_as_of']??null)===$input['metadata']['source_as_of'])
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
    public function apply(string $org,int $actor,int $limit=100):array {
        return $this->applyFor($org,$actor,$limit,false);
    }

    /** @return array<string,mixed> */
    public function applyUnderlyings(string $org,int $actor,int $limit=100):array {
        return $this->applyFor($org,$actor,$limit,true);
    }

    /** @return array<string,mixed> */
    private function applyFor(string $org,int $actor,int $limit,bool $underlying): array
    {
        if ($actor<1 || $limit<1 || $limit>500) throw new InvalidArgumentException('Invalid draft apply request.');
        $preflight=$this->planFor($org,$underlying);
        if ($preflight['total']>$limit || $preflight['total']===0 || $preflight['blocked']>0) {
            throw new RuntimeException('Draft preflight blocked; no canonical instruments created.');
        }
        $seeds=$this->seeds->listForCanonicalDraft($org);
        $created=0;
        $seen=[];
        foreach ($seeds as $evidence) {
            $input=$underlying
                ?$this->projection->createUnderlyingInput($org,$evidence)
                :$this->projection->createInput($org,$evidence);
            if (isset($seen[$input['symbol']])) continue;
            $seen[$input['symbol']]=true;
            $id=InstrumentId::fromString($input['id']);
            if ($this->instruments->get($org,$id)!==null) continue;
            $this->foundation->createInstrument(new CreateInstrument(
                $org,$actor,'cm-seed-'.substr(hash('sha256',$org.'|'.$input['metadata']['origin'].'|'.$input['symbol']),0,24),$input,
            ));
            ++$created;
        }
        return ['status'=>'DRAFT_ONLY','created'=>$created,'reference_mode'=>$underlying,
            'plan'=>$preflight,'economic_link_verified'=>false];
    }
}
