<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Concurrency\MySqlAdvisoryLock;
use App\Security\SecureDownloadResponseFactory;
use App\Security\SecurityHeadersSubscriber;
use App\Security\SecurityRateLimiter;
use App\Security\SessionCsrfValidator;
use Infrastructure\Media\UploadQuarantineService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsCommand(
    name: 'cos:web:security:smoke',
    description: 'Validate canonical Wave 12.19 Web security contracts.',
)]
final class WebSecuritySmokeCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly SessionCsrfValidator $csrf,
        private readonly SecurityRateLimiter $rateLimiter,
        private readonly MySqlAdvisoryLock $locks,
        private readonly SecurityHeadersSubscriber $headers,
        private readonly SecureDownloadResponseFactory $downloads,
        private readonly UploadQuarantineService $quarantine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->csrfSmoke()) {
            $output->writeln('<error>Session CSRF contract failed.</error>');

            return Command::FAILURE;
        }

        $lockValue = $this->locks->synchronized('wave12.19.smoke', static fn (): string => 'locked');
        if ($lockValue !== 'locked') {
            $output->writeln('<error>Advisory lock contract failed.</error>');

            return Command::FAILURE;
        }

        $subject = 'smoke-' . bin2hex(random_bytes(8));
        $first = $this->rateLimiter->consume('wave12.19.smoke', $subject, 2, 60);
        $second = $this->rateLimiter->consume('wave12.19.smoke', $subject, 2, 60);
        $third = $this->rateLimiter->consume('wave12.19.smoke', $subject, 2, 60);

        if (!$first->allowed || !$second->allowed || $third->allowed || $third->retryAfterSeconds <= 0) {
            $output->writeln('<error>Rate limit contract failed.</error>');

            return Command::FAILURE;
        }

        if (!$this->securityHeadersSmoke()) {
            $output->writeln('<error>Security response headers contract failed.</error>');

            return Command::FAILURE;
        }

        if (!$this->downloadSmoke()) {
            $output->writeln('<error>Secure download contract failed.</error>');

            return Command::FAILURE;
        }

        if (!$this->quarantineSmoke()) {
            $output->writeln('<error>Upload quarantine contract failed.</error>');

            return Command::FAILURE;
        }

        $output->writeln('COS Web Security runtime passed.');

        return Command::SUCCESS;
    }

    private function csrfSmoke(): bool
    {
        $request = Request::create('/sales/deals/1', 'POST');
        $session = new Session(new MockArraySessionStorage());
        $session->set('cos_csrf_token', 'wave12-security-token');
        $request->setSession($session);
        $request->headers->set('X-CSRF-Token', 'wave12-security-token');

        return $this->csrf->isValid($request);
    }

    private function securityHeadersSmoke(): bool
    {
        $request = Request::create('https://cos.example.test/admin', 'GET');
        $response = new Response('ok');
        $event = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->headers->onResponse($event);

        return str_contains((string) $response->headers->get('Content-Security-Policy'), "default-src 'self'")
            && $response->headers->get('X-Content-Type-Options') === 'nosniff'
            && $response->headers->get('X-Frame-Options') === 'DENY'
            && str_contains((string) $response->headers->get('Strict-Transport-Security'), 'max-age=');
    }

    private function downloadSmoke(): bool
    {
        $path = $this->kernel->getProjectDir() . '/var/security-download-smoke.txt';
        file_put_contents($path, 'security smoke');

        try {
            $response = $this->downloads->file($path, "security\r\nreport.txt", 'text/plain');

            return $response->headers->get('X-Content-Type-Options') === 'nosniff'
                && str_contains((string) $response->headers->get('Cache-Control'), 'no-store')
                && str_contains((string) $response->headers->get('Content-Disposition'), 'attachment');
        } finally {
            @unlink($path);
        }
    }

    private function quarantineSmoke(): bool
    {
        $source = $this->kernel->getProjectDir() . '/var/security-upload-source.txt';
        file_put_contents($source, 'quarantine smoke');

        $upload = $this->quarantine->quarantine([
            'name' => 'smoke.txt',
            'tmp_name' => $source,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ], 1024);

        try {
            return is_file($upload->path)
                && !str_contains(str_replace('\\', '/', $upload->path), '/public/')
                && $upload->checksum !== '';
        } finally {
            $upload->discard();
            @unlink($source);
        }
    }
}
