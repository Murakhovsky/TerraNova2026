<?php
declare(strict_types=1);

namespace App\Application\Growth\Integration;

use Domains\Growth\Application\Contract\GrowthEngagementResponseBoundary;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Throwable;

final readonly class GrowthEngagementResponseWebhook
{
    private const MAX_BODY_BYTES=131072;

    public function __construct(
        private GrowthEngagementResponseBoundary $responses,
        private ActiveModuleResolver $modules,
        private string $secret,
        private int $maxClockSkew=300,
    ) {
        if($maxClockSkew<30||$maxClockSkew>3600){
            throw new InvalidArgumentException('Growth response webhook max clock skew must be between 30 and 3600 seconds.');
        }
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    public function handle(string $rawBody,string $signature,string $timestamp,string $idempotencyKey):array
    {
        if($this->secret==='')return $this->response(503,false,'Growth engagement response webhook is not configured.');
        if($rawBody===''||strlen($rawBody)>self::MAX_BODY_BYTES){
            return $this->response(413,false,'Growth response webhook body must be between 1 byte and 128 KB.');
        }
        if(!$this->validSignature($rawBody,$signature,$timestamp)){
            return $this->response(401,false,'Invalid or expired Growth response webhook signature.');
        }

        $idempotencyKey=trim($idempotencyKey);
        if($idempotencyKey===''||mb_strlen($idempotencyKey)>191){
            return $this->response(422,false,'X-TN-Idempotency-Key is required.');
        }

        try{$payload=json_decode($rawBody,true,512,JSON_THROW_ON_ERROR);}
        catch(Throwable){return $this->response(400,false,'Growth response webhook body must be valid JSON.');}
        if(!is_array($payload)||array_is_list($payload)){
            return $this->response(400,false,'Growth response webhook body must be a JSON object.');
        }

        $organizationId=trim((string)($payload['organization_id']??''));
        $actionId=trim((string)($payload['kernel_action_id']??''));
        $channel=strtolower(trim((string)($payload['channel']??'')));
        $body=$payload['body']??null;
        $occurredAt=trim((string)($payload['occurred_at']??''));

        if(!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/',$organizationId)){
            return $this->response(422,false,'organization_id is invalid.');
        }
        if($actionId===''||mb_strlen($actionId)>80)return $this->response(422,false,'kernel_action_id is invalid.');
        if(!is_string($body)||trim($body)==='')return $this->response(422,false,'body is required.');
        if($occurredAt==='')return $this->response(422,false,'occurred_at is required.');
        if(!$this->modules->isEnabled($organizationId,'growth')){
            return $this->response(403,false,'Growth module is disabled for this organization.');
        }

        try{
            $result=$this->responses->recordExternalResponse(
                $organizationId,CorrelationId::generate()->value(),$idempotencyKey,$actionId,$channel,$body,$occurredAt,
                $this->nullable($payload['provider_reference']??null),
                $this->nullable($payload['thread_reference']??null),
            );
        }catch(InvalidArgumentException $error){
            $status=str_contains(strtolower($error->getMessage()),'conflicts')?409:422;
            return $this->response($status,false,$error->getMessage());
        }catch(Throwable){
            return $this->response(500,false,'Growth engagement response ingestion failed.');
        }

        return [
            'status'=>!empty($result['replayed'])?200:201,
            'payload'=>[
                'ok'=>true,'response_id'=>$result['response_id']??null,'candidate_id'=>$result['candidate_id']??null,
                'channel'=>$result['channel']??null,'replayed'=>(bool)($result['replayed']??false),
            ],
        ];
    }

    private function validSignature(string $rawBody,string $signature,string $timestamp):bool
    {
        if(!ctype_digit($timestamp)||abs(time()-(int)$timestamp)>$this->maxClockSkew)return false;
        $provided=preg_replace('/^sha256=/i','',trim($signature))?:'';
        if(strlen($provided)!==64||!ctype_xdigit($provided))return false;
        $expected=hash_hmac('sha256',$timestamp.'.'.$rawBody,$this->secret);
        return hash_equals($expected,strtolower($provided));
    }

    private function nullable(mixed $value):?string
    {
        if($value===null)return null;
        if(!is_string($value))throw new InvalidArgumentException('Growth response optional webhook field must be a string.');
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    private function response(int $status,bool $ok,string $message):array
    {
        return ['status'=>$status,'payload'=>['ok'=>$ok,'message'=>$message]];
    }
}
