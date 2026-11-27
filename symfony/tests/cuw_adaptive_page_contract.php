<?php
declare(strict_types=1);

use App\Application\Identity\Service\AccountAuthenticationService;
use App\Infrastructure\Persistence\MySql\MysqlIdentityRepository;
use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Security\CosIdentityLoader;
use App\Security\RoleTenantPermissionResolver;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Adaptive\AdaptiveExperienceResolver;
use App\Web\Experience\Adaptive\AdaptivePagePresentation;
use App\Web\Experience\Adaptive\ExperienceMode;
use App\Web\Experience\Adaptive\ExperienceSemantic;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Kernel\Tenant\Service\TenantContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

require dirname(__DIR__).'/vendor/autoload.php';

// Executed only in the disposable localhost runtime acceptance. All DB
// fixtures are rolled back, including credential/session-related updates.
if (getenv('UX04_COMPONENT_FIXTURE_SAFE')!=='1') {
    throw new RuntimeException('UX-04 component needs explicit disposable database approval.');
}
$params=(new DsnParser(['mysql'=>'pdo_mysql']))->parse((string)getenv('DATABASE_URL'));
if (!in_array($params['host']??null,['mysql','127.0.0.1','localhost'],true)) {
    throw new RuntimeException('UX-04 component must never use a remote database.');
}
$db=DriverManager::getConnection($params);
$expect=static function(bool $ok,string $why):void {
    if (!$ok) throw new RuntimeException('UX-04 SSR component: '.$why);
};
$request=static function(string $method='GET',array $data=[]):Request {
    $r=Request::create('/workspace/unified?reference=fixture',$method,$data);
    $session=new Session(new MockArraySessionStorage());
    $session->set('cos_csrf_token','ux04-component-csrf');
    $r->setSession($session);
    return $r;
};
$post=static fn(array $fields):Request=>$request('POST',['csrf_token'=>'ux04-component-csrf']+$fields);
$status=static fn(mixed $r,int $code):bool=>$r instanceof Response && $r->getStatusCode()===$code;
$checks=[];
$add=static function(string $name) use (&$checks):void {$checks[]=['name'=>$name,'status'=>'PASS'];};

$db->beginTransaction();
try {
    $pdo=$db->getNativeConnection();
    $expect($pdo instanceof PDO,'expected canonical MySQL PDO identity connection');
    $identityLoader=new CosIdentityLoader(new MysqlIdentityRepository($pdo));
    $factory=new TenantContextFactory(new RoleTenantPermissionResolver());
    $tag=bin2hex(random_bytes(6));
    $password=bin2hex(random_bytes(24));
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $identities=[];
    foreach (['a'=>'admin','b'=>'admin','buyer'=>'buyer'] as $key=>$role) {
        $org='ux04-'.$tag.'-'.($key==='buyer'?'a':$key);
        if ($key!=='buyer') $db->insert('cos_organizations',['id'=>$org,'name'=>'UX04 fixture '.$key,'status'=>'ACTIVE']);
        $email='ux04-'.$tag.'-'.$key.'@example.test';
        $db->insert('tn_users',['organization_id'=>$org,'email'=>$email,'password_hash'=>$hash,
            'full_name'=>'UX04 fixture '.$key,'role'=>$role,'status'=>'active']);
        $id=(string)$db->lastInsertId();
        $db->insert('cos_organization_memberships',['organization_id'=>$org,'user_id'=>(int)$id,
            'role'=>$role,'status'=>'ACTIVE']);
        // Each account authenticates against its own explicitly configured organization.
        $result=(new AccountAuthenticationService(new PdoConnection($pdo),$org))->authenticate([
            'email'=>$email,'password'=>$password,
        ]);
        $expect(($result['ok']??false)===true && (string)$result['user']['id']===$id
            && $result['user']['organization_id']===$org,'independent organization authentication failed');
        $securityUser=$identityLoader->loadUserByIdentifier($id.'|'.rawurlencode($org));
        $identities[$key]=['actor'=>$factory->fromIdentity($securityUser->identity()),'id'=>$id,'org'=>$org,'email'=>$email];
    }
    $a=$identities['a']['actor']; $b=$identities['b']['actor']; $buyer=$identities['buyer']['actor'];
    $cross=(new AccountAuthenticationService(new PdoConnection($pdo),$identities['b']['org']))->authenticate([
        'email'=>$identities['a']['email'],'password'=>$password,
    ]);
    $expect(($cross['ok']??false)===false,'cross-tenant password login accepted without membership');
    try {
        $identityLoader->loadUserByIdentifier($identities['a']['id'].'|'.$identities['b']['org']);
        throw new RuntimeException('UX-04 SSR component: cross-tenant identity accepted');
    } catch (UserNotFoundException) {}
    $add('independent_tenant_authentication_and_membership_fence');

    $sections=[
        AdaptivePagePresentation::section('ux04.details',ExperienceMode::Expert),
        AdaptivePagePresentation::section('ux04.audit',ExperienceMode::Expert),
        new ExperienceSemantic('ux04.protected','component','Restricted','expert',ExperienceMode::Expert,
            100,'always',[],'sales.restricted'),
    ];
    foreach (['stale_data','approval_required','external_outcome_unknown'] as $signal) {
        $sections[]=new ExperienceSemantic('ux04.'.$signal,'component',$signal,'contextual',
            ExperienceMode::Expert,100,'on_trigger',[$signal]);
    }
    $signals=['stale_data','approval_required','external_outcome_unknown'];
    $store=new FederationExperiencePreferenceStore($db);
    $adapter=new AdaptivePagePresentation(new AdaptiveExperienceResolver(),$store,new SessionCsrfValidator());
    $present=static fn($r,$actor,array $triggers=[])=>$adapter->prepare($r,$actor,'unified.property',
        $sections,$triggers,entity:'shared-reference');
    $get=static fn($actor,array $triggers=[])=>$present($request(),$actor,$triggers);

    foreach (ExperienceMode::cases() as $mode) {
        $r=$present($post(['mode'=>$mode->value]),$a);
        $expect($status($r,303) && str_ends_with($r->headers->get('Location')??'','#cos-adaptive-mode-'.$mode->value),
            'preference update lost internal redirect or focus');
        $view=$get($a,$signals); $other=$get($b);
        $expect(is_array($view) && is_array($other) && $view['mode']===$mode->value && $other['mode']==='result',
            'tenant mode leaked or did not persist');
        foreach ($signals as $signal) {
            $expect(in_array('ux04.'.$signal,$view['composition']->visibleSections,true),
                'critical trusted signal collapsed in '.$mode->value);
            $expect($status($present($post(['section'=>'ux04.'.$signal,'expand'=>'0']),$a,$signals),422),
                'critical signal accepted collapse');
        }
        $expect(!in_array('ux04.protected',$view['composition']->visibleSections,true),'mode bypassed capability fence');
    }
    $expect($status($present($post(['mode'=>'result']),$a),303),'cannot restore Result');
    foreach (['ux04.details','ux04.audit'] as $id) {
        $expect($status($present($post(['section'=>$id,'expand'=>'1']),$a),303),'cannot persist separate section');
    }
    $scope=['org'=>$identities['a']['org'],'user'=>$identities['a']['id']];
    $versionSql="SELECT state_version FROM cos_workspace_experience_states WHERE organization_id=:org AND user_id=:user AND workspace_key='unified.property' AND entity_key='shared-reference'";
    $before=$db->fetchOne($versionSql,$scope);
    $expect($status($present($post(['section'=>'ux04.details','expand'=>'1']),$a),303)
        && $db->fetchOne($versionSql,$scope)===$before,'replay changed state version');
    $expect($store->workspace($a,'unified.property','shared-reference')['expanded_sections']===['ux04.details','ux04.audit']
        && $store->workspace($b,'unified.property','shared-reference')['expanded_sections']===[],
        'section writes lost unrelated preference or crossed tenant');
    $expect($store->workspace($a,'unified.property','other-reference')['expanded_sections']===[],
        'entity scope leaked expansion');
    $expect($status($present($post(['section'=>'ux04.protected','expand'=>'1']),$a),422)
        && $status($present($post(['mode'=>'expert','organization_id'=>$identities['b']['org']]),$a),422)
        && $status($present($post(['mode'=>'expert']),$buyer),403)
        && $status($present($request('POST',['csrf_token'=>'invalid','mode'=>'expert']),$a),403),
        'preference write failed an authorization or CSRF boundary');
    $add('ssr_mode_section_tenant_entity_and_permission_invariants');
    $db->executeStatement("UPDATE cos_experience_profiles SET default_mode='invalid' WHERE organization_id=:org AND user_id=:user",$scope);
    $db->executeStatement("UPDATE cos_workspace_experience_states SET selected_mode='invalid',state_json=:state WHERE organization_id=:org AND user_id=:user AND workspace_key='unified.property' AND entity_key='shared-reference'",
        $scope+['state'=>json_encode(['expanded_sections'=>['ux04.details','ux04.details','Invalid',42,'ux04.protected']],JSON_THROW_ON_ERROR)]);
    $corrupt=$get($a,$signals);
    $expect(is_array($corrupt) && $corrupt['mode']==='result'
        && in_array('ux04.details',$corrupt['composition']->visibleSections,true)
        && !in_array('ux04.protected',$corrupt['composition']->visibleSections,true)
        && !in_array('ux04.audit',$corrupt['composition']->visibleSections,true),
        'corrupt preferences bypassed fallback or capability restriction');
    $add('corrupt_preference_safe_normalization');

    // Failure fixture: a deliberately unreachable loopback port is private
    // to this component connection; the live runtime database is untouched.
    $unavailable=DriverManager::getConnection(['driver'=>'pdo_mysql','host'=>'127.0.0.1','port'=>1,
        'dbname'=>'ux04_unavailable','user'=>'fixture','password'=>'fixture','driverOptions'=>[PDO::ATTR_TIMEOUT=>1]]);
    $fallback=new AdaptivePagePresentation(new AdaptiveExperienceResolver(),
        new FederationExperiencePreferenceStore($unavailable),new SessionCsrfValidator());
    $view=$fallback->prepare($request(),$a,'unified.property',$sections,$signals,
        entity:'shared-reference',fallbackSections:['ux04.details','ux04.protected']);
    $expect(is_array($view) && $view['mode']==='result' && !$view['available']
        && in_array('ux04.details',$view['composition']->visibleSections,true)
        && !in_array('ux04.protected',$view['composition']->visibleSections,true)
        && in_array('ux04.stale_data',$view['composition']->visibleSections,true),
        'preference outage hid permitted fallback or bypassed capability fence');
    $expect($status($fallback->prepare($post(['mode'=>'expert']),$a,'unified.property',$sections,$signals,
        entity:'shared-reference'),503),'outage must not accept preference write');
    $add('preference_outage_safe_result_and_critical_visibility');
} finally {
    $db->rollBack();
}
echo json_encode(['suite'=>'UX-04 SSR component','checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
