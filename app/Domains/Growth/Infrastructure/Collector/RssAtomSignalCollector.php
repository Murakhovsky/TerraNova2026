<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Collector;

use Domains\Growth\Application\Contract\GrowthFeedReaderInterface;
use Domains\Growth\Application\Contract\GrowthSignalFeedRepositoryInterface;
use Domains\Growth\Application\Contract\SignalCollectorInterface;
use Domains\Growth\Application\DTO\CollectedSignal;
use Domains\Growth\Application\DTO\ExternalFeedEntry;
use Domains\Growth\Application\DTO\SignalCollectionBatch;
use Domains\Growth\Application\DTO\SignalCollectionRequest;
use InvalidArgumentException;

final readonly class RssAtomSignalCollector implements SignalCollectorInterface
{
    public function __construct(
        private GrowthSignalFeedRepositoryInterface $feeds,
        private GrowthFeedReaderInterface $reader,
    ) {}

    public function name():string{return 'rss_atom';}

    public function collect(SignalCollectionRequest $request):SignalCollectionBatch
    {
        if($request->cursor!==null){
            throw new InvalidArgumentException('rss_atom collector does not use cursors; source receipts provide dedupe.');
        }
        $feeds=$this->feeds->listEnabled($request->organizationId,200);
        if($feeds===[])return new SignalCollectionBatch([]);

        $perFeed=max(1,min(50,(int)ceil($request->limit/count($feeds))+2));
        $items=[];
        foreach($feeds as $feed){
            $this->assertFeedRow($feed);
            foreach($this->reader->read($request->organizationId,(string)$feed['url'],$perFeed) as $entry){
                $items[]=$this->signal($feed,$entry);
            }
        }

        usort($items,static function(CollectedSignal $a,CollectedSignal $b):int{
            $cmp=$b->occurredAt<=>$a->occurredAt;
            return $cmp!==0?$cmp:strcmp($a->externalKey,$b->externalKey);
        });

        return new SignalCollectionBatch(array_slice($items,0,$request->limit),null);
    }

    /** @param array<string,mixed> $feed */
    private function signal(array $feed,ExternalFeedEntry $entry):CollectedSignal
    {
        $subjectType=(string)$feed['subject_type'];
        $subjectId=(string)$feed['subject_id'];
        $signalType=(string)$feed['signal_type'];
        $identity=$subjectType.'|'.$subjectId.'|'.$signalType.'|'.$entry->externalId;
        $facts=[
            'title'=>$entry->title,
            'feed_id'=>(string)$feed['feed_id'],
            'feed_name'=>(string)$feed['name'],
            'provider'=>'rss_atom',
        ];
        if($entry->summary!=='')$facts['summary']=$entry->summary;
        if($entry->author!==null)$facts['author']=$entry->author;

        return new CollectedSignal(
            externalKey:'rss_atom:'.substr(hash('sha256',$identity),0,56),
            subjectType:$subjectType,
            subjectId:$subjectId,
            signalType:$signalType,
            facts:$facts,
            sourceReference:$entry->link,
            confidence:(float)$feed['confidence'],
            occurredAt:$entry->occurredAt,
        );
    }

    /** @param array<string,mixed> $feed */
    private function assertFeedRow(array $feed):void
    {
        foreach(['feed_id','name','url','subject_type','subject_id','signal_type'] as $key){
            if(!is_string($feed[$key]??null)||trim((string)$feed[$key])===''){
                throw new InvalidArgumentException('Stored Growth RSS/Atom feed is missing '.$key.'.');
            }
        }
        if(!isset($feed['confidence'])||(!is_int($feed['confidence'])&&!is_float($feed['confidence']))){
            throw new InvalidArgumentException('Stored Growth RSS/Atom feed confidence is invalid.');
        }
    }
}
