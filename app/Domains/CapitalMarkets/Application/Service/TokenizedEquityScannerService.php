<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\TokenizedEquityScannerRepositoryInterface;
use InvalidArgumentException;
use Throwable;

final readonly class TokenizedEquityScannerService
{
    public function __construct(
        private TokenizedEquityScannerRepositoryInterface $repository,
        private TokenizedEquityVerticalSliceService $verticalSlice,
    ){}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function configureTarget(string $organizationId,array $input):array
    {
        $targetId=$this->required($input,'target_id');
        $hypothesis=strtoupper($this->required($input,'hypothesis'));
        if(!in_array($hypothesis,['H1','H2'],true))throw new InvalidArgumentException('hypothesis must be H1 or H2.');
        $enabled=$this->bool($input['enabled']??true);
        $priority=max(0,min(10000,(int)($input['priority']??100)));
        $config=$input['config']??null;
        if(!is_array($config)||array_is_list($config))throw new InvalidArgumentException('config must be an object.');
        $this->validateConfig($hypothesis,$config);

        $this->repository->saveTarget($organizationId,$targetId,$hypothesis,$enabled,$priority,$config);
        foreach($this->repository->listTargets($organizationId,false,2000) as $target){
            if($target['target_id']===$targetId)return $target;
        }
        throw new \RuntimeException('Configured scan target could not be read back.');
    }

    /** @return list<array<string,mixed>> */
    public function targets(string $organizationId,bool $enabledOnly=false,int $limit=500):array
    {
        return $this->repository->listTargets($organizationId,$enabledOnly,$limit);
    }

    /** @return list<array<string,mixed>> */
    public function runs(string $organizationId,int $limit=100):array
    {
        return $this->repository->listRuns($organizationId,$limit);
    }

    /** @return array<string,mixed> */
    public function run(string $organizationId,string $trigger,string $idempotencyKey,int $limit=500):array
    {
        $trigger=trim($trigger)!==''?trim($trigger):'manual';
        $idempotencyKey=trim($idempotencyKey);
        if($idempotencyKey===''||mb_strlen($idempotencyKey)>190){
            throw new InvalidArgumentException('idempotency_key must contain 1..190 characters.');
        }
        if(mb_strlen($trigger)>64)throw new InvalidArgumentException('trigger must contain at most 64 characters.');

        $runId='cm_scan_run_'.substr(hash('sha256',$organizationId.'|'.$idempotencyKey),0,40);
        $started=new DateTimeImmutable();
        if(!$this->repository->claimRun(
            $organizationId,$runId,$idempotencyKey,$trigger,$started->format(DATE_ATOM)
        )){
            $existing=$this->repository->getRunByIdempotencyKey($organizationId,$idempotencyKey);
            if($existing===null)throw new \RuntimeException('Scanner idempotency claim exists but cannot be read.');
            return $this->response($existing,true);
        }

        $targets=$this->repository->listTargets($organizationId,true,$limit);
        $results=[];$completed=0;$failed=0;

        foreach($targets as $target){
            try{
                $result=$this->runTarget($organizationId,$target);
                $completed++;
                $results[]=['target_id'=>$target['target_id'],'hypothesis'=>$target['hypothesis'],'status'=>'COMPLETED','result'=>$result];
            }catch(Throwable $error){
                $failed++;
                $results[]=[
                    'target_id'=>$target['target_id'],'hypothesis'=>$target['hypothesis'],'status'=>'FAILED',
                    'error'=>mb_substr(trim($error->getMessage())!==''?$error->getMessage():get_class($error),0,500),
                ];
            }
        }

        $finished=new DateTimeImmutable();
        $status=$failed===0?'COMPLETED':($completed===0&&$targets!==[]?'FAILED':'PARTIAL');
        $canonical=array_map(static function(array $row):array{
            if(isset($row['result'])&&is_array($row['result'])){
                unset($row['result']['research_observation']['observed_at']);
            }
            return $row;
        },$results);
        $datasetHash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $payload=['results'=>$results,'dataset_hash'=>$datasetHash];
        $this->repository->saveRun(
            $organizationId,$runId,$idempotencyKey,$status,count($targets),$completed,$failed,
            $payload,$finished->format(DATE_ATOM)
        );

        return [
            'run_id'=>$runId,'idempotency_key'=>$idempotencyKey,'trigger'=>$trigger,'status'=>$status,
            'target_count'=>count($targets),'completed_count'=>$completed,'failed_count'=>$failed,
            'dataset_hash'=>$datasetHash,'results'=>$results,'replayed'=>false,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function response(array $row,bool $replayed):array
    {
        $payload=$row['result']??[];
        if(!is_array($payload)||array_is_list($payload))$payload=[];
        return [
            'run_id'=>(string)($row['run_id']??''),
            'idempotency_key'=>(string)($row['idempotency_key']??''),
            'trigger'=>(string)($row['trigger']??''),
            'status'=>(string)($row['status']??''),
            'target_count'=>(int)($row['target_count']??0),
            'completed_count'=>(int)($row['completed_count']??0),
            'failed_count'=>(int)($row['failed_count']??0),
            'dataset_hash'=>(string)($payload['dataset_hash']??''),
            'results'=>is_array($payload['results']??null)?$payload['results']:[],
            'replayed'=>$replayed,
        ];
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function runTarget(string $organizationId,array $target):array
    {
        $hypothesis=(string)$target['hypothesis'];
        $config=$target['config']??[];
        if(!is_array($config)||array_is_list($config))throw new InvalidArgumentException('Persisted scan target config is invalid.');
        $options=$config['options']??[];
        if(!is_array($options)||array_is_list($options))throw new InvalidArgumentException('Persisted scan target options are invalid.');

        if($hypothesis==='H1'){
            return $this->verticalSlice->scanReference(
                $organizationId,$this->required($config,'market_pair_id'),$this->required($config,'reference_source_id'),
                $this->required($config,'underlying_instrument_id'),$this->required($config,'token_venue_id'),
                $this->required($config,'token_instrument_id'),$options
            );
        }
        return $this->verticalSlice->scanCrossVenue(
            $organizationId,$this->required($config,'market_pair_id'),$this->required($config,'venue_a_id'),
            $this->required($config,'instrument_a_id'),$this->required($config,'venue_b_id'),
            $this->required($config,'instrument_b_id'),$options
        );
    }

    /** @param array<string,mixed> $config */
    private function validateConfig(string $hypothesis,array $config):void
    {
        $required=$hypothesis==='H1'
            ?['market_pair_id','reference_source_id','underlying_instrument_id','token_venue_id','token_instrument_id']
            :['market_pair_id','venue_a_id','instrument_a_id','venue_b_id','instrument_b_id'];
        foreach($required as $key)$this->required($config,$key);
        $options=$config['options']??null;
        if(!is_array($options)||array_is_list($options))throw new InvalidArgumentException('config.options must be an object.');
        foreach(['buy_fee_rate','sell_fee_rate'] as $key){
            if(!array_key_exists($key,$options))throw new InvalidArgumentException('config.options.'.$key.' is required.');
        }
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key):string
    {
        $value=trim((string)($input[$key]??''));
        if($value===''||mb_strlen($value)>190)throw new InvalidArgumentException($key.' is required.');
        return $value;
    }

    private function bool(mixed $value):bool
    {
        if(is_bool($value))return $value;
        return filter_var($value,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE)??false;
    }
}
