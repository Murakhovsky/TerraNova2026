<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$client=$read('app/Infrastructure/Llm/OpenCodeStructuredLlmClient.php');
$ctrl=$read('symfony/src/Web/Administration/PlatformSettingsController.php');
$services=$read('symfony/config/services.yaml');
$routes=$read('symfony/config/routes.yaml');
$view=$read('symfony/templates/experience/administration/settings/llm.html.twig');
foreach (['COS_OPENCODE_ENABLED','COS_OPENCODE_ENDPOINT','opencode:', 'OpenCodeStructuredLlmClient'] as $s)
 if(!str_contains($services,$s))throw new RuntimeException('OpenCode wiring missing '.$s);
foreach (['cos_web_platform_settings_opencode_test','/admin/settings/llm/opencode/test','methods: [POST]'] as $s)
 if(!str_contains($routes,$s))throw new RuntimeException('OpenCode route missing '.$s);
foreach (['isValid($request)',"useCase: 'opencode.test'",'cos.opencode.last_test','organizationId: $tenant->organizationId()->value()'] as $s)
 if(!str_contains($ctrl,$s))throw new RuntimeException('OpenCode manual access control missing '.$s);
foreach (['/api/experimental/generate', "request->useCase !== 'opencode.test'", 'allowedHost', 'CURLOPT_FOLLOWLOCATION', "['data']['text']"] as $s)
 if(!str_contains($client,$s))throw new RuntimeException('OpenCode transport boundary missing '.$s);
foreach (['opencode_enabled','Тестувати OpenCode','opencode_result','opencode_error'] as $s)
 if(!str_contains($view,$s))throw new RuntimeException('OpenCode UX missing '.$s);
if(str_contains($ctrl, "['openai','http','fixture','opencode']"))
 throw new RuntimeException('OpenCode must not be globally selectable for commercial workloads.');
echo "OpenCode admin bridge architecture PASS.\n";
