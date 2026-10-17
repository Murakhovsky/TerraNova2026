<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$services=$read('symfony/config/services.yaml');
$routes=$read('symfony/config/routes.yaml');
$controller=$read('symfony/src/Web/Administration/ChatGptPlanConnectionController.php');
$oauth=$read('app/Infrastructure/Llm/ChatGptPlanOAuth.php');
$provider=$read('app/Infrastructure/Llm/ChatGptPlanStructuredLlmClient.php');
foreach(['COS_SIWC_ENABLED','ChatGptPlanOAuth','chatgpt_plan:'] as $v)
 if(!str_contains($services,$v))throw new RuntimeException('SIWC wiring: '.$v);
foreach(['cos_chatgpt_plan_connect','cos_chatgpt_plan_callback','cos_chatgpt_plan_disconnect','cos_chatgpt_plan_test'] as $v)
 if(!str_contains($routes,$v))throw new RuntimeException('SIWC route: '.$v);
foreach(['isValid($request)','hash_equals','SESSION_KEY','deleteSecret','putSecret','verifiedSubject'] as $v)
 if(!str_contains($controller,$v))throw new RuntimeException('SIWC control: '.$v);
foreach(['code_challenge_method','RS256','openssl_verify','PLAN_SCOPE','nonce'] as $v)
 if(!str_contains($oauth,$v))throw new RuntimeException('SIWC OAuth: '.$v);
foreach(["'store'=>false","'stream'=>true",'response.completed','siwc.test'] as $v)
 if(!str_contains($provider,$v))throw new RuntimeException('SIWC plan: '.$v);
echo "SIWC architecture boundaries passed.\n";
