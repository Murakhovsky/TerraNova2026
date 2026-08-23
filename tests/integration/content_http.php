<?php
declare(strict_types=1);

use Common\Services\DatabaseService;
use Modules\Frontend\Services\ContentService;

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
require APP_PATH . '/config/loader.php';

$config = require APP_PATH . '/config/config.php';
$database = new DatabaseService($config->database);
$pdo = $database->connection();
$content = new ContentService($database);
$baseUrl = rtrim((string) ($_ENV['TEST_BASE_URL'] ?? 'http://127.0.0.1:8001'), '/');
$email = 'content-http-' . bin2hex(random_bytes(5)) . '@example.test';
$password = 'ContentTest-' . bin2hex(random_bytes(8));
$userId = 0;
$contentIds = [];
$curl = null;

try {
    $statement = $pdo->prepare('
        INSERT INTO tn_users (email, password_hash, full_name, role, status)
        VALUES (:email, :password_hash, "Content HTTP Test", "manager", "active")
    ');
    $statement->execute([
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_TIMEOUT => 15,
    ]);

    curl_setopt_array($curl, [
        CURLOPT_URL => $baseUrl . '/auth/login',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['email' => $email, 'password' => $password]),
    ]);
    $loginBody = (string) curl_exec($curl);
    if (curl_errno($curl) !== 0 || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200 || !str_contains($loginBody, 'Кабінет')) {
        throw new RuntimeException('Manager login failed: ' . curl_error($curl));
    }

    foreach (['/admin/content' => 'Блог і SEO', '/admin/content/edit' => 'Новий матеріал'] as $path => $marker) {
        curl_setopt_array($curl, [
            CURLOPT_URL => $baseUrl . $path,
            CURLOPT_HTTPGET => true,
        ]);
        $body = (string) curl_exec($curl);
        if (curl_errno($curl) !== 0 || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200 || !str_contains($body, $marker)) {
            throw new RuntimeException(sprintf('HTTP assertion failed for %s: %s', $path, curl_error($curl)));
        }
    }

    foreach (['blog_post' => '/blog/', 'seo_landing' => '/guide/'] as $type => $path) {
        $suffix = bin2hex(random_bytes(5));
        $title = $type === 'blog_post' ? 'HTTP test article ' . $suffix : 'HTTP test landing ' . $suffix;
        $saved = $content->save([
            'content_type' => $type,
            'status' => 'published',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'excerpt' => 'Temporary public content route verification.',
            'body_html' => '<h2>Route verification</h2><p>Temporary integration content.</p>',
            'meta_title' => $title . ' | Terra Nova',
            'meta_description' => str_repeat('Temporary SEO description for route verification. ', 3),
        ], ['id' => $userId], 'integration-test', false);
        if (empty($saved['ok'])) {
            throw new RuntimeException('Temporary public content could not be saved.');
        }
        $contentIds[] = (int) $saved['id'];

        curl_setopt_array($curl, [
            CURLOPT_URL => $baseUrl . $path . $saved['slug'],
            CURLOPT_HTTPGET => true,
        ]);
        $body = (string) curl_exec($curl);
        if (curl_errno($curl) !== 0 || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200 || !str_contains($body, $title)) {
            throw new RuntimeException(sprintf('Public content assertion failed for %s: %s', $path, curl_error($curl)));
        }
    }

    echo "content admin HTTP test passed\n";
} finally {
    $curl = null;
    foreach ($contentIds as $contentId) {
        $pdo->prepare('DELETE FROM tn_integration_outbox WHERE entity_type = "content" AND entity_id = :id')->execute(['id' => $contentId]);
        $pdo->prepare('DELETE FROM tn_content_items WHERE id = :id')->execute(['id' => $contentId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM tn_users WHERE id = :id')->execute(['id' => $userId]);
    }
}
