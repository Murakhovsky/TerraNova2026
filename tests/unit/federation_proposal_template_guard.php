<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Platform\Orchestration\Goal\FederationProposalTemplateGuard;

$guard = new FederationProposalTemplateGuard();
$plans=[['steps'=>[
    ['id'=>'qualify'],['id'=>'prepare'],['id'=>'handoff'],
    ['id'=>'proposal','input'=>['parameters'=>['variables'=>[
        'lead_name'=>'Test Person','account_name'=>'Test Business',
        'expected_value'=>'$1000',
    ]]]],
]]];
$guard->assertResolvable(
    'Offer for {{lead_name}} at {{account_name}}. Value {{expected_value}}.',
    $plans,
);
$guard->assertResolvable('Static document with no placeholders.', $plans);
$invalid=[
    'Unknown {{client_phone}}',
    'Missing {{lead_name',
    'Empty {{}}',
    'Unsupported {{ lead_name }}',
    'Unsupported {{UpperCase}}',
];
foreach($invalid as $template) {
    try { $guard->assertResolvable($template,$plans); }
    catch(DomainException){continue;}
    throw new RuntimeException('Malformed/unknown proposal template passed preflight: '.$template);
}
$empty=$plans;
$empty[0]['steps'][3]['input']['parameters']['variables']['lead_name']='';
try {
    $guard->assertResolvable('{{lead_name}}', $empty);
    throw new RuntimeException('Empty PII/template field silently became a finished document.');
} catch(DomainException) {}
echo "Federation proposal template guard: known variables, missing/empty and malformed placeholders PASS.\n";
