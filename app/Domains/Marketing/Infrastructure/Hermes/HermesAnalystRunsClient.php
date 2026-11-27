<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingAnalystRemoteRunJournal;

/**
 * Bounded worker-only Runs API protocol. Submission is recorded before the network
 * call; the returned handle is durable before polling or requesting a stop.
 * Neither an acknowledgement nor a timeout is a successful local outcome.
 */
final readonly class HermesAnalystRunsClient
{
    private const ACTIVE=['started','queued','running'];
    private const TERMINAL=['completed','failed','cancelled','interrupted'];
    private const UNRESOLVED=['unknown'];

    public function __construct(private HermesAnalystTransport $transport,private MarketingAnalystRemoteRunJournal $journal){}

    public function execute(string $base,string $token,string $key,string $org,string $localId,string $actor,
        string $profile,string $hash,string $instructions,string $input):array
    {
        $this->journal->beginRemoteSubmission($org,$localId,$actor,$profile,$hash);
        // No replay, even if the gateway accepted a lost response.
        $submitted=$this->transport->post($base.'/runs',$token,$key,['input'=>$input,'instructions'=>$instructions]);
        $remoteId=$submitted['run_id']??null;
        if(!is_string($remoteId)||!preg_match('/^run_[A-Za-z0-9_-]{1,124}$/D',$remoteId)
            ||($submitted['status']??null)!=='started')throw new DomainException('Hermes run admission uncertain.');
        $this->journal->attachRemoteRun($org,$localId,$remoteId);
        $url=$base.'/runs/'.$remoteId;
        // At most six reads (5s transport timeout each), with no retry of POST.
        for($i=0;$i<6;$i++){
            $response=$this->poll($url,$token,$org,$localId,$remoteId);
            $status=$response['status'];
            if($status==='completed'){
                if(!is_string($response['output']??null)||!is_array($response['usage']??null)
                    ||!empty($response['tool_calls'])||!empty($response['pending_controls']))
                    throw new DomainException('Malformed Hermes Runs result.');
                $usage=$response['usage'];
                // Normalize to the existing exact token receipt schema. No raw remote metadata persisted.
                return ['id'=>$remoteId,'choices'=>[['finish_reason'=>'stop','message'=>[
                    'role'=>'assistant','content'=>$response['output']]]],
                    'usage'=>['prompt_tokens'=>$usage['input_tokens']??null,
                        'completion_tokens'=>$usage['output_tokens']??null,'total_tokens'=>$usage['total_tokens']??null]];
            }
            if(in_array($status,[...self::TERMINAL,...self::UNRESOLVED],true))throw new DomainException('Hermes run did not complete.');
            // Never approve remote tools. A waiting-for-approval run must stop.
            if($status==='waiting_for_approval')break;
            if(!in_array($status,[...self::ACTIVE,'stopping'],true))throw new DomainException('Hermes run state uncertain.');
            if($i<5)usleep(2000000);
        }
        // Only a locally bound, active run can be stopped, exactly once. A stop
        // acknowledgement is NOT cancellation. Unknown executions require operator recovery.
        if($status!=='stopping'){
            $this->journal->requestRemoteStop($org,$localId,$remoteId);
            $stopKey='cos-mkt-'.hash('sha256',$key.':stop:'.$remoteId);
            $stop=$this->transport->post($url.'/stop',$token,$stopKey,[]);
            if(($stop['status']??null)!=='stopping'||(isset($stop['run_id'])&&$stop['run_id']!==$remoteId))
                throw new DomainException('Hermes stop acknowledgement uncertain.');
            $this->journal->observeRemoteRun($org,$localId,$remoteId,'stopping');
        }
        for($i=0;$i<2;$i++){
            $response=$this->poll($url,$token,$org,$localId,$remoteId);
            if(in_array($response['status'],[...self::TERMINAL,...self::UNRESOLVED],true))break;
            if($i===0)usleep(1000000);
        }
        // Never accept advice after the worker deadline/stop path, even if completion raced stop.
        throw new DomainException('Hermes run deadline or tool-control refusal; independent reconciliation required.');
    }

    private function poll(string $url,string $token,string $org,string $localId,string $remoteId):array
    {
        $response=$this->transport->get($url,$token);
        $status=$response['status']??null;
        if(($response['object']??null)!=='hermes.run'||($response['run_id']??null)!==$remoteId
            ||!is_string($status)||!in_array($status,[...self::ACTIVE,...self::TERMINAL,...self::UNRESOLVED,'waiting_for_approval','stopping'],true))
            throw new DomainException('Hermes run status/identity uncertain.');
        $this->journal->observeRemoteRun($org,$localId,$remoteId,$status);
        return $response;
    }
}
