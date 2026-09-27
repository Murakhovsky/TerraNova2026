<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Collector;

use Domains\Growth\Application\Contract\GrowthJsonSignalReaderInterface;
use Domains\Growth\Application\Contract\GrowthJsonSignalSourceRepositoryInterface;
use Domains\Growth\Application\Contract\SignalCollectorInterface;
use Domains\Growth\Application\DTO\CollectedSignal;
use Domains\Growth\Application\DTO\ExternalJsonSignalEntry;
use Domains\Growth\Application\DTO\SignalCollectionBatch;
use Domains\Growth\Application\DTO\SignalCollectionRequest;
use Domains\Growth\Domain\GrowthJsonSignalSource;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class CredentialedJsonSignalCollector implements SignalCollectorInterface
{
    public function __construct(
        private GrowthJsonSignalSourceRepositoryInterface $sources,
        private GrowthJsonSignalReaderInterface $reader,
    ) {}

    public function name():string{return 'credentialed_json';}

    public function collect(SignalCollectionRequest $request):SignalCollectionBatch
    {
        if($request->cursor!==null){
            throw new InvalidArgumentException('credentialed_json collector does not use cursors; source receipts provide dedupe.');
        }
        $rows=$this->sources->listEnabled($request->organizationId,200);
        if($rows===[])return new SignalCollectionBatch([]);

        $perSource=max(1,min(50,(int)ceil($request->limit/count($rows))+2));
        $items=[];
        foreach($rows as $row){
            $source=$this->source($row);
            foreach($this->reader->read($source,$perSource) as $entry){
                $items[]=$this->signal($source,$entry);
            }
        }

        usort($items,static function(CollectedSignal $a,CollectedSignal $b):int{
            $cmp=$b->occurredAt<=>$a->occurredAt;
            return $cmp!==0?$cmp:strcmp($a->externalKey,$b->externalKey);
        });
        return new SignalCollectionBatch(array_slice($items,0,$request->limit),null);
    }

    private function signal(GrowthJsonSignalSource $source,ExternalJsonSignalEntry $entry):CollectedSignal
    {
        $identity=$source->id.'|'.$source->subjectType.'|'.$source->subjectId.'|'.$source->signalType.'|'.$entry->externalId;
        $facts=$entry->facts;
        $facts['json_source_id']=$source->id;
        $facts['json_source_name']=$source->name;
        $facts['provider']='credentialed_json';

        return new CollectedSignal(
            externalKey:'credentialed_json:'.substr(hash('sha256',$identity),0,48),
            subjectType:$source->subjectType,
            subjectId:$source->subjectId,
            signalType:$source->signalType,
            facts:$facts,
            sourceReference:$entry->sourceReference,
            confidence:$source->confidence,
            occurredAt:$entry->occurredAt,
        );
    }

    /** @param array<string,mixed> $row */
    private function source(array $row):GrowthJsonSignalSource
    {
        foreach([
            'source_id','name','url','auth_mode','credential_reference','subject_type','subject_id','signal_type'
        ] as $key){
            if(!is_string($row[$key]??null)||trim((string)$row[$key])===''){
                throw new InvalidArgumentException('Stored Growth JSON signal source is missing '.$key.'.');
            }
        }
        if(!isset($row['confidence'])||(!is_int($row['confidence'])&&!is_float($row['confidence']))){
            throw new InvalidArgumentException('Stored Growth JSON signal source confidence is invalid.');
        }
        return new GrowthJsonSignalSource(
            (string)$row['source_id'],OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['name'],(string)$row['url'],(string)$row['auth_mode'],(string)$row['credential_reference'],
            isset($row['api_key_header'])&&$row['api_key_header']!==null?(string)$row['api_key_header']:null,
            (string)$row['subject_type'],(string)$row['subject_id'],(string)$row['signal_type'],(float)$row['confidence'],true,
        );
    }
}
