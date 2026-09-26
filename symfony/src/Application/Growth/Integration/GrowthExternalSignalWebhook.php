<?php
declare(strict_types=1);

namespace App\Application\Growth\Integration;

use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Throwable;

final readonly class GrowthExternalSignalWebhook
{
    private const MAX_BODY_BYTES=1_048_576;

    public function __construct(
        private GrowthApplicationBoundary $growth,
        private ActiveModuleResolver $modules,
        private string $secret,
        private int $actorId,
        private int $maxClockSkew=300,
    ) {
        if($maxClockSkew<30||$maxClockSkew>3600){
            throw new InvalidArgumentException('Growth webhook max clock skew must be between 30 and 3600 seconds.');
        }
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    public function handle(
        string $rawBody,
        string $signature,
        string $timestamp,
        string $idempotencyKey,
    ): array {
        if($this->secret===''||$this->actorId<=0){
            return $this->response(503,false,'Growth external signal webhook is not configured.');
        }
        if($rawBody===''||strlen($rawBody)>self::MAX_BODY_BYTES){
            return $this->response(413,false,'Growth webhook body must be between 1 byte and 1 MB.');
        }
        if(!$this->validSignature($rawBody,$signature,$timestamp)){
            return $this->response(401,false,'Invalid or expired Growth webhook signature.');
        }

        $idempotencyKey=trim($idempotencyKey);
        if($idempotencyKey===''||mb_strlen($idempotencyKey)>191){
            return $this->response(422,false,'X-TN-Idempotency-Key is required.');
        }

        try{
            $payload=json_decode($rawBody,true,512,JSON_THROW_ON_ERROR);
        }catch(Throwable){
            return $this->response(400,false,'Growth webhook body must be valid JSON.');
        }
        if(!is_array($payload)||array_is_list($payload)){
            return $this->response(400,false,'Growth webhook body must be a JSON object.');
        }

        $organizationId=trim((string)($payload['organization_id']??''));
        $source=strtolower(trim((string)($payload['source']??'')));
        $signal=$payload['signal']??null;

        if(!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/',$organizationId)){
            return $this->response(422,false,'organization_id is invalid.');
        }
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/',$source)){
            return $this->response(422,false,'source is invalid.');
        }
        if(!is_array($signal)||array_is_list($signal)){
            return $this->response(422,false,'signal must be a JSON object.');
        }
        if(!$this->modules->isEnabled($organizationId,'growth')){
            return $this->response(403,false,'Growth module is disabled for this organization.');
        }

        $correlationId=CorrelationId::generate()->value();

        try{
            $result=$this->growth->ingestExternalSignal(
                $organizationId,$this->actorId,$correlationId,$source,$idempotencyKey,$signal,
            );
        }catch(InvalidArgumentException $error){
            $message=$error->getMessage();
            $status=str_contains(strtolower($message),'idempotency')?409:422;
            return $this->response($status,false,$message);
        }catch(Throwable){
            return $this->response(500,false,'Growth external signal ingestion failed.');
        }

        return [
            'status'=>!empty($result['replayed'])?200:201,
            'payload'=>[
                'ok'=>true,
                'signal_id'=>$result['signal_id']??null,
                'organization_id'=>$organizationId,
                'source'=>$source,
                'replayed'=>(bool)($result['replayed']??false),
                'correlation_id'=>$correlationId,
            ],
        ];
    }

    private function validSignature(string $rawBody,string $signature,string $timestamp): bool
    {
        if(!ctype_digit($timestamp)||abs(time()-(int)$timestamp)>$this->maxClockSkew){
            return false;
        }
        $provided=preg_replace('/^sha256=/i','',trim($signature))?:'';
        if(strlen($provided)!==64||!ctype_xdigit($provided))return false;
        $expected=hash_hmac('sha256',$timestamp.'.'.$rawBody,$this->secret);
        return hash_equals($expected,strtolower($provided));
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    private function response(int $status,bool $ok,string $message): array
    {
        return ['status'=>$status,'payload'=>['ok'=>$ok,'message'=>$message]];
    }
}
