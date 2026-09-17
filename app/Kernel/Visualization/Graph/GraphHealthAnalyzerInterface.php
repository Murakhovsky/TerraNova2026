<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

interface GraphHealthAnalyzerInterface
{
    /**
     * @return array{
     *   status:string,
     *   total:int,
     *   errors:int,
     *   warnings:int,
     *   info:int,
     *   issues:list<array{code:string,severity:string,node_id:string,message:string,metadata:array<string,mixed>}>
     * }
     */
    public function analyze(Graph $graph): array;
}
