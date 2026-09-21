<?php
declare(strict_types=1);

namespace App\Web\Auth;

use App\Application\Identity\Service\AccountAuthenticationService;
use App\Security\LoginRateLimiter;
use App\Web\Phtml\PhtmlRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthPageController
{
    public function __construct(
        private AccountAuthenticationService $accounts,
        private PhtmlRenderer $renderer,
        private LoginRateLimiter $loginRateLimiter,
    ) {}

    public function login(Request $request): Response
    {
        if($this->authenticated($request))return new RedirectResponse('/cabinet');
        $status=null;$form=$request->request->all();
        if($request->isMethod('POST')){
            $identity=$this->loginIdentity($request,$form);
            $decision=$this->loginRateLimiter->consume($identity);
            if(!$decision->allowed){
                $response=$this->page($request,'auth/login','Вхід',[
                    'authStatus'=>'Забагато невдалих спроб. Спробуйте пізніше.',
                    'formData'=>$form,
                ],Response::HTTP_TOO_MANY_REQUESTS);
                $response->headers->set('Retry-After',(string)$decision->retryAfterSeconds());
                return $response;
            }
            $result=$this->accounts->authenticate($form);$status=(string)($result['message']??'');
            if(($result['ok']??false)===true&&is_array($result['user']??null)){
                $this->loginRateLimiter->clear($identity);
                $this->establish($request,$result['user']);
                return new RedirectResponse('/cabinet');
            }
        }
        return $this->page($request,'auth/login','Вхід',['authStatus'=>$status,'formData'=>$form]);
    }

    public function register(Request $request): Response
    {
        if($this->authenticated($request))return new RedirectResponse('/cabinet');
        $status=null;$form=$request->request->all();
        if($request->isMethod('POST')){
            $result=$this->accounts->register($form);$status=(string)($result['message']??'');
            if(($result['ok']??false)===true&&is_array($result['user']??null)){ $this->establish($request,$result['user']);return new RedirectResponse('/cabinet');}
        }
        return $this->page($request,'auth/register','Реєстрація',['authStatus'=>$status,'formData'=>$form]);
    }

    public function logout(Request $request): Response
    {
        if($request->hasSession())$request->getSession()->invalidate();
        return new RedirectResponse('/');
    }

    private function establish(Request $request,array $user): void
    {
        $session=$request->getSession();$session->migrate(true);
        $session->set('tn_auth_user_id',(int)$user['id']);
        $session->set('cos_organization_id',(string)($user['organization_id']??'default'));
        $session->set('cos_csrf_token',bin2hex(random_bytes(32)));
    }

    private function authenticated(Request $request): bool
    {
        return $request->hasSession()&&(int)$request->getSession()->get('tn_auth_user_id',0)>0;
    }

    private function loginIdentity(Request $request,array $form): string
    {
        $ip=trim((string)($request->getClientIp()??'unknown'));
        $login=mb_strtolower(trim((string)($form['email']??$form['login']??'')));

        return $ip.'|'.$login;
    }

    private function page(Request $request,string $view,string $title,array $variables,int $status=Response::HTTP_OK): Response
    {
        return new Response($this->renderer->render($request,$view,array_replace([
            'title'=>$title,'metaTitle'=>$title.' | Terra Nova CLUB','metaRobots'=>'noindex,nofollow',
            'interfaceSurface'=>'public','pageAssetEntries'=>['public-surface'],'currentUser'=>null,
        ],$variables)),$status);
    }
}
