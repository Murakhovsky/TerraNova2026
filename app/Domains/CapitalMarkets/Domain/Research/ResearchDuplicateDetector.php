<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

final class ResearchDuplicateDetector
{
    /** @param list<array<string,mixed>> $existing @return list<array<string,mixed>> */
    public function find(array $candidate,array $existing,float $threshold=0.72):array
    {
        $candidateTokens=$this->tokens($candidate);
        if($candidateTokens===[])return [];
        $matches=[];

        foreach($existing as $row){
            $tokens=$this->tokens($row);
            if($tokens===[])continue;
            $intersection=count(array_intersect_key($candidateTokens,$tokens));
            $union=count($candidateTokens+$tokens);
            $score=$union===0?0:$intersection/$union;
            if($score<$threshold)continue;
            $matches[]=[
                'hypothesis_id'=>$row['hypothesis_id']??$row['id']??null,
                'code'=>$row['code']??null,
                'status'=>$row['status']??null,
                'similarity'=>$score,
                'title'=>$row['title']??null,
            ];
        }

        usort($matches,static fn(array $a,array $b):int=>$b['similarity']<=>$a['similarity']);
        return $matches;
    }

    private function tokens(array $row):array
    {
        $text=strtolower(implode(' ',[
            (string)($row['title']??''),
            (string)($row['description']??''),
            (string)($row['economic_reason']??''),
            (string)($row['expected_behavior']??''),
            (string)($row['edge_source']??''),
            implode(' ',(array)($row['markets']??[])),
            implode(' ',(array)($row['venues']??[])),
        ]));
        $parts=preg_split('/[^a-z0-9_:-]+/',$text)?:[];
        $tokens=[];
        foreach($parts as $part){
            if(strlen($part)<3)continue;
            $tokens[$part]=true;
        }
        return $tokens;
    }
}
