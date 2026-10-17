<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Infrastructure\Llm\ChatGptPlanOAuth;
use Infrastructure\Llm\ChatGptPlanStructuredLlmClient;
use Kernel\Llm\LlmProviderException;
use Kernel\Llm\StructuredLlmRequest;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;
use Platform\Settings\Contract\PlatformSettingsWriterInterface;

$uri='https://company-os.shop/admin/settings/llm/chatgpt/callback';
$off=new ChatGptPlanOAuth(false,'oaiapp_test','',$uri);
if($off->configured())throw new RuntimeException('Feature flag must default off.');
$oauth=new ChatGptPlanOAuth(true,'oaiapp_test','',$uri,
 static fn(string $url,?array $fields):array=>[200,'{"scope":"openid chatgpt.tokens.use.direct","access_token":"test"}']);
$a=$oauth->start();$b=$oauth->start();
if($a['state']===$b['state'] || $a['verifier']===$b['verifier']
 || !str_contains($a['url'],'code_challenge_method=S256'))
 throw new RuntimeException('State/PKCE is invalid.');
if(!$oauth->hasPlanScope(['scope'=>'openid chatgpt.tokens.use.direct'])
 || $oauth->hasPlanScope(['scope'=>'openid email']))
 throw new RuntimeException('Granted plan scope not independently checked.');
if(($oauth->redeem('code','verifier')['access_token'] ?? '')!=='test')
 throw new RuntimeException('OAuth exchange failed.');
$cred=json_encode(['sub'=>'user-a','access_token'=>'at','refresh_token'=>'rt',
 'scope'=>'openid chatgpt.tokens.use.direct','expires_at'=>time()+3600]);
$reader=new class($cred) implements PlatformSettingsReaderInterface{
 public function __construct(private string $v){}
 public function value(string $organizationId,string $namespace,string $key,mixed $fallback=null):mixed{return $fallback;}
 public function secret(string $organizationId,string $namespace,string $key,?string $fallback=null):?string{
  return $organizationId==='tenant-a' && $namespace==='chatgpt_plan' && $key==='credentials.user-a' ? $this->v : null;
 }
 public function namespace(string $organizationId,string $namespace):array{return [];}
};
$writer=new class implements PlatformSettingsWriterInterface{
 public function put(string $organizationId,string $namespace,string $key,mixed $value,string $type,string $actorId,string $correlationId):void{}
 public function putSecret(string $organizationId,string $namespace,string $key,string $value,string $actorId,string $correlationId):void{}
 public function delete(string $organizationId,string $namespace,string $key,string $actorId,string $correlationId):void{}
 public function deleteSecret(string $organizationId,string $namespace,string $key,string $actorId,string $correlationId):void{}
};
$calls=0;
$client=new ChatGptPlanStructuredLlmClient($oauth,$reader,$writer,
 static function(string $url,string $token,string $body)use(&$calls):array{
  ++$calls;$payload=json_decode($body,true,512,JSON_THROW_ON_ERROR);
  if(($payload['store'] ?? null)!==false || ($payload['stream'] ?? null)!==true
   || $token!=='at' || $url!=='https://api.openai.com/v1/responses')
   throw new RuntimeException('Plan inference contract violated.');
  return [200,"event: response.completed\ndata: ".json_encode([
   'type'=>'response.completed','response'=>[
    'id'=>'resp-test','model'=>'gpt-test','usage'=>['input_tokens'=>2,'output_tokens'=>2],
    'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'{"answer":"COS OK"}']]]],
   ]],JSON_THROW_ON_ERROR)."\n\n"];
 });
$schema=['type'=>'object','properties'=>['answer'=>['type'=>'string']],
 'required'=>['answer'],'additionalProperties'=>false];
$r=$client->smoke('tenant-a','user-a','gpt-test','Hello',$schema);
if($r->output['answer']!=='COS OK' || $r->costAmount!==null || $calls!==1)
 throw new RuntimeException('SSE smoke failed.');
try{$client->smoke('tenant-b','user-a','gpt-test','Hello',$schema);throw new RuntimeException('Cross-tenant leak.');}
catch(LlmProviderException $e){if($e->retryable)throw $e;}
try{$client->complete(new StructuredLlmRequest('sys','Hello',[],$schema,
 model:'gpt-test',organizationId:'tenant-a',useCase:'agent.engineering',actorId:'user-a'));
 throw new RuntimeException('Autonomous plan use was allowed.');
}catch(LlmProviderException $e){if($e->retryable)throw $e;}
echo "SIWC gated pilot contracts passed.\n";
