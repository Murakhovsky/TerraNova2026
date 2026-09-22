<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

/**
 * Wave 12.23 browser E2E evidence.
 *
 * CI runs this suite against the already-started canonical Symfony runtime
 * through PANTHER_EXTERNAL_BASE_URI, so the production image can stay --no-dev.
 */
final class WebExperiencePantherTest extends PantherTestCase
{
    public function testPublicExperienceAndProtectedDevSurface(): void
    {
        $baseUri = getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8081';

        $client = static::createPantherClient([
            'external_base_uri' => $baseUri,
        ]);

        $crawler = $client->request('GET', '/auth/login');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('form')->count());

        $client->request('GET', '/property/catalog');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/dev/ui');
        self::assertStringEndsWith('/auth/login', $client->getCurrentURL());
    }
}
