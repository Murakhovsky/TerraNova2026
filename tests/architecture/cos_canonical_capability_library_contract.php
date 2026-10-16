<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$check=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$get=static function(string $path)use($root):string {
    $txt=file_get_contents($root.'/'.$path);
    if (!is_string($txt)) throw new RuntimeException('Missing '.$path);
    return $txt;
};
$definition=require $root.'/app/Domains/Property/module.php';
$ids=$definition['contributions']['capabilities'];
$contracts=$definition['contributions']['capability_contracts'];
foreach (['property.asset.create','property.asset.search','property.asset.get','property.asset.update'] as $id) {
    $check(in_array($id,$ids,true),'Unregistered '.$id);
    $matching=array_values(array_filter($contracts,static fn(array $c):bool=>($c['id']??'')===$id));
    $check(count($matching)===1,'Missing typed contract '.$id);
    foreach (['input_schema','output_schema'] as $field) {
        $schema=json_decode($get($matching[0][$field]),true,512,JSON_THROW_ON_ERROR);
        $check(($schema['type']??null)==='object','Invalid schema '.$id);
    }
}
$create=array_values(array_filter($contracts,static fn(array $c):bool=>$c['id']==='property.asset.create'))[0];
$check($create['side_effect_level']==='internal'&&$create['idempotency']==='required'
    &&$create['approval_policy']==='conditional','Unsafe direct Property contract.');
$gateway=$get('symfony/src/Application/Capability/CanonicalCapabilityGateway.php');
$controller=$get('symfony/src/Http/Api/V1/Controller/CanonicalCapabilityController.php');
$check(str_contains($gateway,'Automated mutations require separately approved canonical Workflow.')
    &&str_contains($gateway,'workflow_approval_required')
    &&str_contains($gateway,"'human_api','human_ui'"),'AI / Action admission bypass.');
$check(str_contains($gateway,'TenantPermissions::MANAGE')
    &&str_contains($gateway,'$this->modules->isEnabled('),'Missing tenant/permission guard.');
$check(str_contains($controller,'$this->csrf->isValid($request)')
    &&str_contains($controller,"'human_api'"),'HTTP origin or CSRF validation missing.');
$check(str_contains($get('symfony/config/services.yaml'),'!tagged_iterator cos.capability.invoker'),
    'Domain binding not registered.');
$check(str_contains($get('symfony/config/routes.yaml'),'/workspace/capabilities')
    &&str_contains($get('symfony/config/routes.yaml'),'/api/v1/capabilities/{id}/invoke'),
    'Capabilities endpoints missing.');
$check(str_contains($get('symfony/src/Infrastructure/Capability/PropertyAssetCreateAdapter.php'),'new CreatePropertyCommand(')
    &&str_contains($get('symfony/src/Infrastructure/Capability/PropertyAssetSearchAdapter.php'),'new SearchPropertiesQuery('),
    'Adapters bypass existing buses.');
$check(str_contains($gateway,'new CapabilityJsonInputValidator()'), 'Gateway must validate local typed schemas before dispatch.');
$check(str_contains($get('symfony/src/Infrastructure/Capability/PropertyAssetGetAdapter.php'),'new GetPropertyQuery(')
    && str_contains($get('symfony/src/Infrastructure/Capability/PropertyAssetUpdateAdapter.php'),'new UpdatePropertyCommand('),
    'Additional Property capabilities must delegate to existing buses.');
$form=$get('symfony/src/Web/Capability/CapabilityLibraryController.php');
$check(str_contains($form,"'property.asset.create'") && str_contains($form,"'property.asset.get'")
    && str_contains($form,'$this->csrf->isValid($request)')
    && str_contains($get('symfony/templates/experience/capability/library.html.twig'),"path('cos_capabilities_property_create')"),
    'Canonical Property form must reuse Gateway, CSRF and independent read.');
$check(str_contains($get('symfony/config/routes.yaml'),'cos_capabilities_property_create'),
    'Property form route missing.');
$check(!str_contains($gateway,'PDO')&&!str_contains($gateway,'ActionExecutor'),
    'Parallel execution authority not permitted.');
echo "Canonical capability library contract PASS.\n";
