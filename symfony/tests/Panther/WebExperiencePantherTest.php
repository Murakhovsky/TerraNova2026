<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Panther\Client;

/**
 * Wave 12.23 browser E2E evidence.
 *
 * This test drives Chrome against the already-started canonical Symfony
 * runtime. It intentionally does not boot a local Symfony Kernel.
 */
final class WebExperiencePantherTest extends TestCase
{
    private ?Client $client = null;

    protected function tearDown(): void
    {
        $this->client?->quit();
        $this->client = null;
    }

    public function testPublicExperienceAndProtectedDevSurface(): void
    {
        $baseUri = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8081', '/');

        $client = Client::createChromeClient(null, null, [], $baseUri);
        $this->client = $client;

        $crawler = $client->request('GET', '/auth/login');
        self::assertGreaterThan(0, $crawler->filter('form')->count());
        self::assertSame($baseUri.'/auth/login', $client->getCurrentURL());

        $crawler = $client->request('GET', '/property/catalog');
        self::assertGreaterThan(0, $crawler->filter('main')->count());
        self::assertSame($baseUri.'/property/catalog', $client->getCurrentURL());

        $client->request('GET', '/dev/ui');
        self::assertSame($baseUri.'/auth/login', $client->getCurrentURL());
    }
}
