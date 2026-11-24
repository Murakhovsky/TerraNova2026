<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesTeamScopeInterface;
use Domains\Sales\Application\Contract\SalesTeamSummaryReadPort;
use DomainException;

final readonly class SalesTeamSummaryService
{
    public function __construct(private SalesTeamScopeInterface $scope,private SalesTeamSummaryReadPort $read) {}

    public function read(string $org,int $actor,array $input):array
    {
        $team=$input['team_id']??null;$pipeline=$input['pipeline_id']??null;
        if (array_diff(array_keys($input),['team_id','pipeline_id'])!==[]||!is_string($team)||
            (array_key_exists('pipeline_id',$input)&&(!is_string($pipeline)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/D',$pipeline)!==1)))
            throw new DomainException('Invalid team summary input.');
        $owners=$this->scope->members($org,$actor,$team);
        $result=$this->read->summaryForOwners($org,$owners,$pipeline);
        if ($this->scope->members($org,$actor,$team)!==$owners) throw new DomainException('Team scope changed during read.');
        $result['scope']=['team_id'=>$team,'member_count'=>count($owners),'pipeline_id'=>$pipeline];
        return $result;
    }
}
