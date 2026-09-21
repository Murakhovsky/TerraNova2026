<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

/**
 * Wave 12.23 Panther suite. It becomes executable once symfony/panther is a
 * locked dev dependency. Current canonical browser CI is Playwright-based.
 */
if (class_exists(PantherTestCase::class)) {
    final class WebExperiencePantherTest extends PantherTestCase
    {
        public function testPublicExperienceAndProtectedDevSurface(): void
        {
            $client = static::createPantherClient();
            $crawler = $client->request('GET', '/auth/login');
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter('form')->count());
            $client->request('GET', '/property/catalog');
            self::assertResponseIsSuccessful();
            $client->request('GET', '/dev/ui');
            self::assertResponseRedirects('/auth/login');
        }
    }
}
