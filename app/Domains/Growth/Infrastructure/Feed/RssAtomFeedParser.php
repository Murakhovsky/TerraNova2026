<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Feed;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Domains\Growth\Application\DTO\ExternalFeedEntry;
use InvalidArgumentException;
use Throwable;

final class RssAtomFeedParser
{
    /** @return list<ExternalFeedEntry> */
    public function parse(string $xml,int $limit):array
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('RSS/Atom parser limit must be between 1 and 200.');
        if(trim($xml)==='')throw new InvalidArgumentException('RSS/Atom response body is empty.');

        $previous=libxml_use_internal_errors(true);
        try{
            $document=new DOMDocument();
            if(!$document->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT)){
                throw new InvalidArgumentException('RSS/Atom response is not valid XML.');
            }
        }finally{
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath=new DOMXPath($document);
        $nodes=$xpath->query('//*[local-name()="item" or local-name()="entry"]');
        if($nodes===false)throw new InvalidArgumentException('RSS/Atom entries could not be read.');

        $entries=[];
        $seen=[];
        foreach($nodes as $node){
            if(!$node instanceof DOMElement)continue;
            $title=$this->text($xpath,$node,['title']);
            $link=$this->link($xpath,$node);
            $externalId=$this->text($xpath,$node,['guid','id']);
            if($externalId==='')$externalId=$link;
            $date=$this->text($xpath,$node,['pubDate','published','updated','date']);

            if($title===''||$link===''||$externalId===''||$date==='')continue;
            if(filter_var($link,FILTER_VALIDATE_URL)===false)continue;
            $scheme=strtolower((string)parse_url($link,PHP_URL_SCHEME));
            if(!in_array($scheme,['http','https'],true))continue;

            try{
                $occurredAt=new DateTimeImmutable($date);
            }catch(Throwable){
                continue;
            }

            $dedupe=hash('sha256',$externalId);
            if(isset($seen[$dedupe]))continue;
            $seen[$dedupe]=true;

            $summary=$this->clean($this->text($xpath,$node,['description','summary','content']),4000);
            $author=$this->clean($this->text($xpath,$node,['creator','author','name']),500);
            $entries[]=new ExternalFeedEntry(
                externalId:$this->clean($externalId,1000),
                title:$this->clean($title,1000),
                link:$this->clean($link,2000),
                summary:$summary,
                author:$author===''?null:$author,
                occurredAt:$occurredAt,
            );
        }

        usort($entries,static function(ExternalFeedEntry $a,ExternalFeedEntry $b):int{
            $cmp=$b->occurredAt<=>$a->occurredAt;
            return $cmp!==0?$cmp:strcmp($a->externalId,$b->externalId);
        });

        return array_slice($entries,0,$limit);
    }

    /** @param list<string> $names */
    private function text(DOMXPath $xpath,DOMNode $context,array $names):string
    {
        foreach($names as $name){
            $result=$xpath->query('./*[local-name()="'.$name.'"][1]',$context);
            if($result!==false&&$result->length>0){
                $value=trim((string)$result->item(0)?->textContent);
                if($value!=='')return $value;
            }
            if($name==='name'){
                $result=$xpath->query('./*[local-name()="author"]/*[local-name()="name"][1]',$context);
                if($result!==false&&$result->length>0){
                    $value=trim((string)$result->item(0)?->textContent);
                    if($value!=='')return $value;
                }
            }
        }
        return '';
    }

    private function link(DOMXPath $xpath,DOMNode $context):string
    {
        $links=$xpath->query('./*[local-name()="link"]',$context);
        if($links===false)return '';
        foreach($links as $link){
            if(!$link instanceof DOMElement)continue;
            $rel=strtolower(trim($link->getAttribute('rel')));
            if($rel!==''&&!in_array($rel,['alternate','related'],true))continue;
            $href=trim($link->getAttribute('href'));
            if($href!=='')return $href;
            $text=trim($link->textContent);
            if($text!=='')return $text;
        }
        return '';
    }

    private function clean(string $value,int $limit):string
    {
        $value=html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $value=preg_replace('/\s+/u',' ',trim($value))??'';
        return mb_strlen($value)<=$limit?$value:mb_substr($value,0,$limit);
    }
}
