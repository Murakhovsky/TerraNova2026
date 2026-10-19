<?php
declare(strict_types=1);

namespace App\Web\Administration;

use App\Security\SessionCsrfValidator;
use Infrastructure\Llm\ChatGptPlanOAuth;
use Infrastructure\Llm\ChatGptPlanStructuredLlmClient;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Platform\Settings\Contract\PlatformSettingsWriterInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Link a ChatGPT account to an ALREADY authenticated COS admin; not COS login. */
final readonly class ChatGptPlanConnectionController
{
    private const SESSION_KEY='cos.siwc.oauth.transaction';

    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private ChatGptPlanOAuth $oauth,
        private ChatGptPlanStructuredLlmClient $provider,
        private PlatformSettingsWriterInterface $writer,
    ) {}

    public function connect(Request $request): Response
    {
        $tenant=$this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request) || !$request->hasSession())
            return new Response('Invalid CSRF/session.',403);
        try {
            $start=$this->oauth->start();
            $request->getSession()->set(self::SESSION_KEY,[
                'state'=>$start['state'],'nonce'=>$start['nonce'],
                'verifier'=>$start['verifier'],'expires_at'=>$start['expires_at'],
                'organization'=>$tenant->organizationId()->value(),
                'actor'=>(string)$tenant->userId()->value(),
            ]);
            return new RedirectResponse($start['url'],Response::HTTP_SEE_OTHER);
        } catch (Throwable) {
            return $this->redirect('ChatGPT OAuth вимкнено або немає схваленого клієнта OpenAI.');
        }
    }

    public function callback(Request $request): Response
    {
        $tenant=$this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$request->hasSession()) return new Response('OAuth session unavailable.',403);
        $session=$request->getSession();
        $tx=$session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY); // consume once even on failure
        $state=$request->query->get('state');
        if (!is_array($tx) || ($tx['expires_at'] ?? 0)<time()
            || ($tx['organization'] ?? '')!==$tenant->organizationId()->value()
            || ($tx['actor'] ?? '')!==(string)$tenant->userId()->value()
            || !is_string($state) || !is_string($tx['state'] ?? null)
            || !hash_equals($tx['state'],$state))
            return $this->redirect('Недійсна або прострочена OAuth-сесія.');
        if ($request->query->has('error')) return $this->redirect('Доступ ChatGPT не надано.');
        try {
            $tokens=$this->oauth->redeem((string)$request->query->get('code',''),(string)$tx['verifier']);
            if (!$this->oauth->hasPlanScope($tokens))
                return $this->redirect('Відсутній дозвіл ChatGPT plan usage.');
            if (!is_string($tokens['id_token'] ?? null)
                || !is_string($tokens['access_token'] ?? null) || $tokens['access_token']===''
                || !is_string($tokens['refresh_token'] ?? null) || $tokens['refresh_token']==='')
                throw new \RuntimeException('Missing tokens.');
            $sub=$this->oauth->verifiedSubject($tokens['id_token'],(string)$tx['nonce']);
            $actor=(string)$tenant->userId()->value();
            $credentials=json_encode([
                'sub'=>$sub,'access_token'=>$tokens['access_token'],
                'refresh_token'=>$tokens['refresh_token'],'scope'=>(string)$tokens['scope'],
                'expires_at'=>time()+max(1,(int)($tokens['expires_in'] ?? 3600)),
            ],JSON_THROW_ON_ERROR);
            $this->writer->putSecret($tenant->organizationId()->value(),
                'chatgpt_plan','credentials.'.$actor,$credentials,$actor,bin2hex(random_bytes(16)));
            return $this->redirect('ChatGPT підключено для тестів COS.');
        } catch (Throwable $error) {
            error_log('cos.siwc.callback_failed '.get_class($error));
            return $this->redirect('Не вдалося підтвердити OAuth. Перевірте клієнт і дозволи.');
        }
    }

    public function disconnect(Request $request): Response
    {
        $tenant=$this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',403);
        $this->writer->deleteSecret($tenant->organizationId()->value(),
            'chatgpt_plan','credentials.'.(string)$tenant->userId()->value(),
            (string)$tenant->userId()->value(),bin2hex(random_bytes(16)));
        return $this->redirect('ChatGPT відключено локально. Відкликати доступ можна у ChatGPT Settings.');
    }

    public function test(Request $request): Response
    {
        $tenant=$this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',403);
        $model=trim((string)$request->request->get('model',''));
        $prompt=trim((string)$request->request->get('prompt',''));
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/',$model) || $prompt==='' || mb_strlen($prompt)>500)
            return $this->redirect('Вкажіть модель і запит до 500 символів.');
        try {
            $result=$this->provider->smoke(
                $tenant->organizationId()->value(),(string)$tenant->userId()->value(),$model,$prompt,
                ['type'=>'object','properties'=>['answer'=>['type'=>'string']],
                 'required'=>['answer'],'additionalProperties'=>false],
            );
            return $this->redirect('ChatGPT: тест успішний, отримано структуровану відповідь від моделі.');
        } catch (Throwable $error) {
            error_log('cos.siwc.test_failed '.get_class($error));
            return $this->redirect('Тест не пройшов. Перевірте OAuth, scope і модель.');
        }
    }

    private function admin(): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if ($tenant===null) return new RedirectResponse('/auth/login');
        if (!$tenant->isAdmin()) return new Response('Forbidden',403);
        return $tenant;
    }

    private function redirect(string $message): RedirectResponse
    {
        return new RedirectResponse('/admin/settings/llm?status_message='.rawurlencode($message),303);
    }
}
