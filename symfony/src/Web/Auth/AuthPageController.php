<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Application\Identity\Service\AccountAuthenticationService;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class AuthPageController
{
    public function __construct(
        private AccountAuthenticationService $accounts,
        private Environment $twig,
        private PagePresentationFactory $pages,
    ) {
    }

    public function login(Request $request): Response
    {
        if ($this->authenticated($request)) {
            return new RedirectResponse('/cabinet');
        }

        $status = null;
        $form = $request->request->all();

        if ($request->isMethod('POST')) {
            $result = $this->accounts->authenticate($form);
            $status = (string) ($result['message'] ?? '');

            if (($result['ok'] ?? false) === true && is_array($result['user'] ?? null)) {
                $this->establish($request, $result['user']);

                return new RedirectResponse('/cabinet');
            }
        }

        return $this->render('experience/public/auth_login.html.twig', [
            'page' => $this->pages->create(
                PageArchetype::FormEditor,
                ['PageHeader', 'FormSection', 'StickyActions', 'ErrorState'],
                $status === null ? 'normal' : 'error',
            ),
            'authStatus' => $status,
            'formData' => $this->scalarFormData($form),
        ]);
    }

    public function register(Request $request): Response
    {
        if ($this->authenticated($request)) {
            return new RedirectResponse('/cabinet');
        }

        $status = null;
        $form = $request->request->all();

        if ($request->isMethod('POST')) {
            $result = $this->accounts->register($form);
            $status = (string) ($result['message'] ?? '');

            if (($result['ok'] ?? false) === true && is_array($result['user'] ?? null)) {
                $this->establish($request, $result['user']);

                return new RedirectResponse('/cabinet');
            }
        }

        return $this->render('experience/public/auth_register.html.twig', [
            'page' => $this->pages->create(
                PageArchetype::FormEditor,
                ['PageHeader', 'FormSection', 'StickyActions', 'ErrorState'],
                $status === null ? 'normal' : 'error',
            ),
            'authStatus' => $status,
            'formData' => $this->scalarFormData($form),
        ]);
    }

    public function logout(Request $request): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new RedirectResponse('/');
    }

    private function establish(Request $request, array $user): void
    {
        $session = $request->getSession();
        $session->migrate(true);
        $session->set('tn_auth_user_id', (int) $user['id']);
        $session->set('cos_organization_id', (string) ($user['organization_id'] ?? 'default'));
        $session->set('cos_csrf_token', bin2hex(random_bytes(32)));
    }

    private function authenticated(Request $request): bool
    {
        return $request->hasSession()
            && (int) $request->getSession()->get('tn_auth_user_id', 0) > 0;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function scalarFormData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $variables */
    private function render(string $template, array $variables): Response
    {
        return new Response(
            $this->twig->render($template, $variables),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
