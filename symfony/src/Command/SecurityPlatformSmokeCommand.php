<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Security\Contract\MutationLockManagerInterface;
use App\Security\LoginRateLimiter;
use App\Security\PrivateDownloadResponseFactory;
use App\Security\SecurityHeadersSubscriber;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

#[AsCommand(
    name: 'cos:web:security:smoke',
    description: 'Validate canonical COS Web security platform runtime.',
)]
final class SecurityPlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly LoginRateLimiter $rateLimiter,
        private readonly MutationLockManagerInterface $locks,
        private readonly PrivateDownloadResponseFactory $downloads,
        private readonly SecurityHeadersSubscriber $headers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identity = 'wave12.19.' . bin2hex(random_bytes(8));

        try {
            $first = $this->rateLimiter->consume($identity, 2, 60, 60);
            $second = $this->rateLimiter->consume($identity, 2, 60, 60);
            $blocked = $this->rateLimiter->consume($identity, 2, 60, 60);

            if (!$first->allowed || !$second->allowed || $blocked->allowed || $blocked->retryAt === null) {
                $output->writeln('<error>Login rate-limit runtime contract is invalid.</error>');

                return Command::FAILURE;
            }

            $locked = $this->locks->synchronized(
                'wave12.19',
                $identity,
                static fn (): string => 'locked',
                1,
            );

            if ($locked !== 'locked') {
                $output->writeln('<error>Mutation lock runtime contract is invalid.</error>');

                return Command::FAILURE;
            }

            $download = $this->downloads->create('security-smoke', '../unsafe/report.txt', 'text/plain');
            foreach ([
                'Content-Disposition' => 'attachment;',
                'X-Content-Type-Options' => 'nosniff',
            ] as $header => $marker) {
                if (!str_contains((string) $download->headers->get($header), $marker)) {
                    $output->writeln(sprintf('<error>Private download header is invalid: %s</error>', $header));

                    return Command::FAILURE;
                }
            }

            $cacheControl = (string) $download->headers->get('Cache-Control');
            foreach (['private', 'no-store', 'max-age=0'] as $directive) {
                if (!str_contains($cacheControl, $directive)) {
                    $output->writeln(sprintf('<error>Private download cache directive is missing: %s</error>', $directive));

                    return Command::FAILURE;
                }
            }

            $kernel = new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new Response();
                }
            };

            $request = Request::create('/workspace', 'GET');
            $response = new Response('<html><body>COS</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
            $this->headers->onResponse($event);

            $nonce = trim((string) $request->attributes->get(SecurityHeadersSubscriber::CSP_NONCE_ATTRIBUTE, ''));
            $csp = (string) $response->headers->get('Content-Security-Policy');
            if ($nonce === '' || !str_contains($csp, "'nonce-" . $nonce . "'")) {
                $output->writeln('<error>Request-scoped CSP nonce is missing or not bound to the response.</error>');

                return Command::FAILURE;
            }

            foreach ([
                'Content-Security-Policy' => "object-src 'none'",
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Permissions-Policy' => 'camera=()',
            ] as $header => $marker) {
                if (!str_contains((string) $response->headers->get($header), $marker)) {
                    $output->writeln(sprintf('<error>Security response header is invalid: %s</error>', $header));

                    return Command::FAILURE;
                }
            }
        } finally {
            try {
                $this->rateLimiter->clear($identity);
            } catch (Throwable) {
            }
        }

        $output->writeln('COS Web Security Platform runtime passed.');

        return Command::SUCCESS;
    }
}
