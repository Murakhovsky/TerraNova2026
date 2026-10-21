<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Infrastructure\Llm\OpenCodeStructuredLlmClient;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\LlmProviderException;
$schema=['type'=>'object','properties'=>['answer'=>['type'=>'string']],'required'=>['answer'],'additionalProperties'=>false];
$make=static fn(?string $useCase='opencode.test',?string $actor='admin-1',?string $tenant='tenant-a',?string $model=null)=>
 new StructuredLlmRequest('You answer shortly','Hi!',[],$schema,model:$model,organizationId:$tenant,useCase:$useCase,actorId:$actor);
$off=new OpenCodeStructuredLlmClient(false,'http://127.0.0.1:4096','opencode','secret');
if($off->configured())throw new RuntimeException('OpenCode must be off by default.');
$wrongHost=new OpenCodeStructuredLlmClient(true,'http://169.254.169.254:80','opencode','secret');
if($wrongHost->configured())throw new RuntimeException('SSRF host guard failed.');
$noSecret=new OpenCodeStructuredLlmClient(true,'http://127.0.0.1:4096','opencode','');
if($noSecret->configured())throw new RuntimeException('Missing basic auth accepted.');
$calls=0;
$client=new OpenCodeStructuredLlmClient(true,'http://127.0.0.1:4096','opencode','secret','127.0.0.1',30,
 static function(string $url,string $user,string $password,string $body,int $timeout)use(&$calls):array{
   ++$calls;
   $req=json_decode($body,true,512,JSON_THROW_ON_ERROR);
   if($url!=='http://127.0.0.1:4096/api/experimental/generate' || $user!=='opencode' || $password!=='secret' || $timeout!==30)
       throw new RuntimeException('OpenCode HTTP config invalid.');
   if(!str_contains($req['prompt'],'"required":["answer"]'))
       throw new RuntimeException('JSON schema absent from model instruction.');
   return [200,'{"data":{"text":"{\\"answer\\":\\"COS OK\\"}"}}'];
 });
$response=$client->complete($make(model:'openai/gpt-test'));
if($response->output['answer']!=='COS OK'||$response->provider!=='opencode'||$response->costAmount!==null||$calls!==1)
 throw new RuntimeException('OpenCode adapter success mapping failed.');
foreach ([$make('agent.engineering'),$make('opencode.test',null),$make('opencode.test','admin-1',null)] as $invalid) {
 try{$client->complete($invalid);throw new RuntimeException('Unprivileged OpenCode usage allowed.');}
 catch(LlmProviderException $e){if($e->retryable)throw $e;}
}
if($calls!==1)throw new RuntimeException('Blocked request reached OpenCode.');
$bad=new OpenCodeStructuredLlmClient(true,'http://127.0.0.1:4096','opencode','secret','127.0.0.1',30,
 static fn():array=>[200,'{"data":{"text":"{\"unexpected\":true}"}}']);
try{$bad->complete($make());throw new RuntimeException('Missing schema requirement accepted.');}
catch(LlmProviderException $e){if($e->retryable)throw $e;}
echo "OpenCode structured adapter tests PASS.\n";
