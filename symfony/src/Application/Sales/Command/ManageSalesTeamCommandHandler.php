<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use App\Application\Sales\Admin\SalesAdminAuthorization;
use App\Application\Sales\TeamSnapshot;
use DomainException;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

/** Typed team administration uses the same native repository and Sales admin authority as UI. */
final readonly class ManageSalesTeamCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesTeamAdministrationInterface $teams,private SalesAdminAuthorization $authorization,
        private SalesMutationReceiptRepositoryInterface $receipts,private TransactionManagerInterface $transactions) {}

    public function __invoke(ManageSalesTeamCommand $command):array
    {
        $org=$command->organizationId->value();$actor=$command->actorId;$input=$command->input;$key=trim($command->idempotencyKey);
        if ($actor<1 || $key==='' || strlen($key)>191 || !$this->authorization->allows($org,$actor,SalesAdminAuthorization::TEAMS)) throw new DomainException('Sales team administration permission required.');
        $op=$input['operation']??null;$this->validate($op,$input);
        ksort($input);$hash=hash('sha256',json_encode([$actor,$input],JSON_THROW_ON_ERROR));
        return $this->transactions->transactional(function()use($org,$actor,$input,$key,$hash,$op):array{
            $id=$input['team_id']??null;
            $operation='team.'.$op;
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$this->receipts->claim($org,$operation,$key,$pending)) {
                $receipt=$this->receipts->find($org,$operation,$key);
                if ($op==='create') {
                    if ($receipt===null || preg_match('/^([a-f0-9]{64}):([a-f0-9]{32})$/D',$receipt,$p)!==1 || !hash_equals($hash,$p[1])) throw new DomainException('Team receipt conflict.');
                    $id=$p[2];
                } elseif ($receipt===null || !hash_equals('done:'.$hash,$receipt)) throw new DomainException('Team receipt conflict.');
                $state=$this->read($org,$id);
                if (!$this->matches($op,$input,$state)) throw new DomainException('Team state changed after original operation.');
                return ['team'=>$state,'replayed'=>true];
            }
            if ($op==='create') {
                $created=$this->teams->createTeam($org,['code'=>$input['code'],'name'=>$input['name'],'assignment_mode'=>$input['assignment_mode']??'MANUAL'],(string)$actor);
                $id=$created['id']??null;
                if (!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1) throw new RuntimeException('Team creation returned no native identity.');
                $done=$hash.':'.$id;
            } elseif ($op==='update') {
                $mode=$input['assignment_mode']??$this->read($org,$id)['assignment_mode'];
                $this->teams->updateTeam($org,$id,['name'=>$input['name'],'status'=>$input['status'],'assignment_mode'=>$mode],$input['configuration_version'],(string)$actor);
                $done='done:'.$hash;
            } else {
                $this->teams->setMember($org,$id,$input['user_id'],[
                    'role'=>$input['role'],'status'=>$input['member_status'],'assignment_enabled'=>$input['assignment_enabled'],
                    'approval_enabled'=>$input['approval_enabled'],'assignment_weight'=>$input['assignment_weight'],
                    'configuration_version'=>$input['configuration_version'],
                ],(string)$actor);
                $done='done:'.$hash;
            }
            $state=$this->read($org,$id);
            if (!$this->matches($op,$input,$state)) throw new RuntimeException('Team mutation native readback failed.');
            if (!$this->receipts->complete($org,$operation,$key,$pending,$done)) throw new RuntimeException('Team mutation receipt failed.');
            return ['team'=>$state,'replayed'=>false];
        });
    }

    private function validate(mixed $op,array $input):void
    {
        $required=match($op){
            'create'=>['operation','code','name'],
            'update'=>['operation','team_id','configuration_version','name','status'],
            'member_set'=>['operation','team_id','configuration_version','user_id','role','member_status','assignment_enabled','approval_enabled','assignment_weight'],
            default=>throw new DomainException('Unknown team operation.'),
        };
        if (array_diff($required,array_keys($input))!==[] || array_diff(array_keys($input),$op==='member_set'?$required:array_merge($required,['assignment_mode']))!==[]) throw new DomainException('Invalid team operation fields.');
        if (array_key_exists('assignment_mode',$input)&&!in_array($input['assignment_mode'],['MANUAL','ROUND_ROBIN'],true)) throw new DomainException('Invalid assignment mode.');
        if (isset($input['team_id']) && (!is_string($input['team_id'])||preg_match('/^[A-Za-z0-9_-]{8,40}$/D',$input['team_id'])!==1
            || !is_int($input['configuration_version'])||$input['configuration_version']<1)) throw new DomainException('Invalid team revision.');
        if (isset($input['name'])&&(!is_string($input['name'])||trim($input['name'])!==$input['name']||$input['name']===''||mb_strlen($input['name'])>160)) throw new DomainException('Invalid team name.');
        if ($op==='create'&&(!is_string($input['code'])||preg_match('/^[A-Z0-9_]{2,80}$/D',$input['code'])!==1)) throw new DomainException('Invalid team code.');
        if ($op==='update'&&!in_array($input['status'],['ACTIVE','DISABLED','ARCHIVED'],true)) throw new DomainException('Invalid team status.');
        if ($op==='member_set'&&(!is_int($input['user_id'])||$input['user_id']<1||!in_array($input['role'],['LEAD','MEMBER'],true)
            || !in_array($input['member_status'],['ACTIVE','INACTIVE'],true)||!is_bool($input['assignment_enabled'])||!is_bool($input['approval_enabled'])
            || !is_int($input['assignment_weight'])||$input['assignment_weight']<1||$input['assignment_weight']>100
            || ($input['member_status']==='INACTIVE'&&($input['assignment_enabled']||$input['approval_enabled'])))) throw new DomainException('Invalid team member.');
    }

    private function read(string $org,?string $id):array
    {
        if ($id===null) throw new DomainException('Missing team identity.');
        $row=$this->teams->team($org,$id);
        if ($row===null||($row['id']??null)!==$id) throw new DomainException('Tenant-owned team not found.');
        return TeamSnapshot::fromRow($row);
    }

    private function matches(string $op,array $input,array $state):bool
    {
        if ($op==='create')return $state['code']===$input['code']&&$state['name']===$input['name']&&$state['status']==='ACTIVE'&&$state['assignment_mode']===($input['assignment_mode']??'MANUAL');
        if ($op==='update')return $state['name']===$input['name']&&$state['status']===$input['status']&&(!isset($input['assignment_mode'])||$state['assignment_mode']===$input['assignment_mode']);
        foreach($state['members'] as $member)if($member['user_id']===$input['user_id'])return $member['role']===$input['role']&&$member['status']===$input['member_status']
            &&$member['assignment_enabled']===$input['assignment_enabled']&&$member['approval_enabled']===$input['approval_enabled']&&$member['assignment_weight']===$input['assignment_weight'];
        return false;
    }
}
