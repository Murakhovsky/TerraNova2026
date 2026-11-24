<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DomainException;
use Domains\Sales\Application\Contract\SalesCommercialAnalyticsReadPort;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;

/** Application boundary shared by future Workspace and capability adapters. */
final readonly class SalesCommercialAnalyticsService
{
    public function __construct(
        private SalesWorkspaceScopeInterface $scope,
        private SalesCommercialAnalyticsReadPort $read,
    ) {}

    public function read(string $org,int $actor,string $view,array $input):array
    {
        if (array_diff(array_keys($input),['scope','owner_id','pipeline_id','from','to'])!==[]
            || !in_array($view,['outcomes','forecast'],true)
            || !is_string($input['from']??null)||!is_string($input['to']??null)) {
            throw new DomainException('Invalid Sales analytics input.');
        }
        $mode=$input['scope']??'self';$owner=$input['owner_id']??null;$pipeline=$input['pipeline_id']??null;
        if (!in_array($mode,['self','owner','tenant'],true)
            ||(array_key_exists('owner_id',$input)&&(!is_int($owner)||$owner<1))
            ||(array_key_exists('pipeline_id',$input)&&(!is_string($pipeline)||$pipeline===''))) {
            throw new DomainException('Invalid Sales analytics scope.');
        }
        $authorized=$this->scope->resolve($org,$actor,$mode,$owner);
        $result=match($view) {
            'outcomes'=>$this->read->outcomes($org,$authorized,$pipeline,$input['from'],$input['to']),
            'forecast'=>$this->read->forecast($org,$authorized,$pipeline,$input['from'],$input['to']),
        };
        if ($this->scope->resolve($org,$actor,$mode,$owner)!==$authorized) {
            throw new DomainException('Sales analytics scope changed during read.');
        }
        return $result;
    }
}
