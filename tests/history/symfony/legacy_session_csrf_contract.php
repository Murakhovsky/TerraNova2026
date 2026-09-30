<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Security\LegacySessionCsrfValidator;
use App\Security\LegacySessionReader;
use Symfony\Component\HttpFoundation\Request;

function csrfWave2(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$dir = sys_get_temp_dir() . '/cos-wave2-csrf-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$sessionId = 'wave2-session-000001';
$token = str_repeat('ab', 32);
file_put_contents(
    $dir . '/sess_' . $sessionId,
    'tn_auth_user_id|i:1001;cos_organization_id|s:7:"default";cos_csrf_token|s:64:"' . $token . '";'
);

$reader = new LegacySessionReader($dir, 'PHPSESSID');
csrfWave2(($reader->read($sessionId)['organization_id'] ?? null) === 'default', 'Legacy identity parsing regressed.');
csrfWave2($reader->csrfToken($sessionId) === $token, 'Legacy CSRF token was not parsed.');

$validator = new LegacySessionCsrfValidator($reader);
$request = Request::create('/api/v1/sales/leads', 'POST', [], ['PHPSESSID' => $sessionId], [], [
    'HTTP_X_CSRF_TOKEN' => $token,
    'CONTENT_TYPE' => 'application/json',
], json_encode(['full_name' => 'Lead'], JSON_THROW_ON_ERROR));
csrfWave2($validator->isValid($request), 'Header CSRF token must validate.');

$request->headers->set('X-CSRF-Token', str_repeat('cd', 32));
csrfWave2(!$validator->isValid($request), 'Wrong CSRF token must fail.');

$jsonRequest = Request::create('/api/v1/sales/leads', 'POST', [], ['PHPSESSID' => $sessionId], [], [
    'CONTENT_TYPE' => 'application/json',
], json_encode(['csrf_token' => $token], JSON_THROW_ON_ERROR));
csrfWave2($validator->isValid($jsonRequest), 'JSON body CSRF token must validate.');

@unlink($dir . '/sess_' . $sessionId);
@rmdir($dir);

echo "Symfony legacy-session CSRF bridge passed.\n";
