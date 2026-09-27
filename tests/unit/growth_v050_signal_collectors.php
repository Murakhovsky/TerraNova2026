<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\SignalCollectorInterface;
use Domains\Growth\Application\DTO\CollectedSignal;
use Domains\Growth\Application\DTO\SignalCollectionBatch;
use Domains\Growth\Application\DTO\SignalCollectionRequest;
use Domains\Growth\Application\Service\SignalCollectorRegistry;

function expectGrowthV050(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$collector=new class implements SignalCollectorInterface {
    public function name(): string { return 'fixture'; }
    public function collect(SignalCollectionRequest $request): SignalCollectionBatch
    {
        return new SignalCollectionBatch([
            new CollectedSignal(
                'ext-1','account','GACC-1','executive_change',
                ['role'=>'COO','change'=>'joined'],'https://example.test/event/1',0.95,
                new DateTimeImmutable('2026-09-22T07:00:00+00:00'),
            ),
        ],'cursor-2');
    }
};
$registry=new SignalCollectorRegistry([$collector]);
expectGrowthV050($registry->names()===['fixture'],'Growth collector registry must expose deterministic names.');
expectGrowthV050($registry->get('fixture')===$collector,'Growth collector registry lookup failed.');

$batch=$collector->collect(new SignalCollectionRequest('org-1',null,100));
expectGrowthV050(count($batch->items)===1,'Growth collector batch item missing.');
expectGrowthV050($batch->items[0]->signalType==='executive_change','Growth collected signal normalization failed.');
expectGrowthV050($batch->nextCursor==='cursor-2','Growth collector cursor propagation failed.');
expectGrowthV050(($batch->items[0]->fingerprintPayload()['source_reference']??null)==='https://example.test/event/1','Growth source provenance was lost.');

try{
    new SignalCollectorRegistry([$collector,$collector]);
    throw new RuntimeException('Duplicate Growth collectors must be rejected.');
}catch(InvalidArgumentException){}

echo "Growth V0.5 Signal Collector contracts passed.\n";
