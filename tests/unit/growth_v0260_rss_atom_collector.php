<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthFeedReaderInterface;
use Domains\Growth\Application\Contract\GrowthSignalFeedRepositoryInterface;
use Domains\Growth\Application\DTO\ExternalFeedEntry;
use Domains\Growth\Application\DTO\SignalCollectionRequest;
use Domains\Growth\Domain\GrowthSignalFeed;
use Domains\Growth\Infrastructure\Collector\RssAtomSignalCollector;
use Domains\Growth\Infrastructure\Feed\RssAtomFeedParser;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV0260(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$feed=new GrowthSignalFeed(
    'GSFD-1',OrganizationId::fromString('org-1'),'Example newsroom','https://example.test/feed.xml',
    'account','account-1','company_news',0.82,true,
);
expectGrowthV0260($feed->enabled(),'Growth SignalFeed must preserve enabled state.');
$feed->disable();
expectGrowthV0260(!$feed->enabled(),'Growth SignalFeed disable failed.');
$feed->enable();
expectGrowthV0260($feed->enabled(),'Growth SignalFeed enable failed.');

try{
    new GrowthSignalFeed(
        'GSFD-BAD',OrganizationId::fromString('org-1'),'Bad','http://example.test/feed.xml',
        'account','account-1','company_news',0.8,true,
    );
    throw new RuntimeException('Growth SignalFeed must reject non-HTTPS URL.');
}catch(InvalidArgumentException){}

$parser=new RssAtomFeedParser();
$rss=<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>New operating unit announced</title>
      <link>https://example.test/news/2</link>
      <guid>news-2</guid>
      <pubDate>Wed, 23 Sep 2026 13:00:00 +0000</pubDate>
      <description><![CDATA[<p>The company opened a second operating unit.</p>]]></description>
      <author>press@example.test</author>
    </item>
    <item>
      <title>Earlier update</title>
      <link>https://example.test/news/1</link>
      <guid>news-1</guid>
      <pubDate>Wed, 23 Sep 2026 12:00:00 +0000</pubDate>
      <description>Earlier company update.</description>
    </item>
    <item>
      <title>Broken item</title>
      <guid>broken</guid>
    </item>
  </channel>
</rss>
XML;
$rssEntries=$parser->parse($rss,10);
expectGrowthV0260(count($rssEntries)===2,'RSS parser must skip incomplete items.');
expectGrowthV0260($rssEntries[0]->externalId==='news-2','RSS parser must order newest first.');
expectGrowthV0260($rssEntries[0]->summary==='The company opened a second operating unit.','RSS parser must normalize HTML summary.');

$atom=<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <id>tag:example.test,2026:3</id>
    <title>Leadership change</title>
    <link rel="alternate" href="https://example.test/news/3"/>
    <updated>2026-09-23T14:00:00+00:00</updated>
    <summary>New COO joined.</summary>
    <author><name>Ada Editor</name></author>
  </entry>
</feed>
XML;
$atomEntries=$parser->parse($atom,10);
expectGrowthV0260(count($atomEntries)===1,'Atom parser must read entry.');
expectGrowthV0260($atomEntries[0]->link==='https://example.test/news/3','Atom parser must resolve href link.');
expectGrowthV0260($atomEntries[0]->author==='Ada Editor','Atom parser must read author.');

$feeds=new class implements GrowthSignalFeedRepositoryInterface {
    public array $rows=[[
        'organization_id'=>'org-1','feed_id'=>'GSFD-1','name'=>'Example newsroom',
        'url'=>'https://example.test/feed.xml','subject_type'=>'account','subject_id'=>'account-1',
        'signal_type'=>'company_news','confidence'=>0.82,'enabled'=>true,
    ]];
    public function create(GrowthSignalFeed $feed,int $actorId):void{}
    public function lock(string $organizationId,string $feedId):GrowthSignalFeed{throw new RuntimeException('unused');}
    public function update(GrowthSignalFeed $feed,int $actorId):void{}
    public function view(string $organizationId,string $feedId):?array{return null;}
    public function listAll(string $organizationId,int $limit=200):array{return $this->rows;}
    public function listEnabled(string $organizationId,int $limit=200):array{return $this->rows;}
};
$reader=new class implements GrowthFeedReaderInterface {
    public function read(string $organizationId,string $url,int $limit):array
    {
        return [
            new ExternalFeedEntry(
                'entry-1','Expansion announced','https://example.test/entry-1',
                'Company expands.','Editor',new DateTimeImmutable('2026-09-23T15:00:00+00:00'),
            ),
            new ExternalFeedEntry(
                'entry-2','Hiring update','https://example.test/entry-2',
                'New hiring program.','Editor',new DateTimeImmutable('2026-09-23T14:00:00+00:00'),
            ),
        ];
    }
};
$collector=new RssAtomSignalCollector($feeds,$reader);
expectGrowthV0260($collector->name()==='rss_atom','RSS/Atom collector canonical name mismatch.');
$batch=$collector->collect(new SignalCollectionRequest('org-1',null,10));
expectGrowthV0260(count($batch->items)===2,'RSS/Atom collector must map feed entries.');
$first=$batch->items[0];
expectGrowthV0260($first->subjectType==='account'&&$first->subjectId==='account-1','RSS/Atom collector lost configured subject.');
expectGrowthV0260($first->signalType==='company_news','RSS/Atom collector lost configured signal type.');
expectGrowthV0260(abs($first->confidence-0.82)<0.0001,'RSS/Atom collector lost configured confidence.');
expectGrowthV0260(($first->facts['provider']??null)==='rss_atom','RSS/Atom collector provenance is missing.');
expectGrowthV0260(str_starts_with($first->externalKey,'rss_atom:'),'RSS/Atom collector external key namespace is wrong.');

try{
    $collector->collect(new SignalCollectionRequest('org-1','cursor-not-supported',10));
    throw new RuntimeException('RSS/Atom collector must reject cursor use.');
}catch(InvalidArgumentException){}

echo "Growth V0.26 Tenant RSS/Atom Signal Collector contracts passed.\n";
