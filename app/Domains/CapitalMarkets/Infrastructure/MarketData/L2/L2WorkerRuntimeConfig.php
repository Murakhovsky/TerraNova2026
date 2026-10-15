<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Explicit, fail-closed runtime configuration for public L2 streams.
 * No default venue, symbol or production publishing side effect.
 */
final readonly class L2WorkerRuntimeConfig
{
    public function __construct(
        public L2SubscriptionConfig $subscription,
        public int $messageBudget,
        public int $maxAttempts,
        public int $staleAfterSeconds,
        public bool $enabled,
    ) {
        if ($messageBudget < 1 || $messageBudget > 1000000
            || $maxAttempts < 1 || $maxAttempts > 1000
            || $staleAfterSeconds < 1 || $staleAfterSeconds > 3600) {
            throw new DomainException('Invalid L2 runtime limits.');
        }
    }

    /** @param array<string,string> $environment */
    public static function fromEnvironment(array $environment): self
    {
        foreach (['FOS_L2_VENUE','FOS_L2_SYMBOL','FOS_L2_ENABLED'] as $key) {
            if (!isset($environment[$key]) || $environment[$key] === '') {
                throw new DomainException('Missing required L2 environment setting: '.$key);
            }
        }
        $enabled = match ($environment['FOS_L2_ENABLED']) {
            '1' => true,
            '0' => false,
            default => throw new DomainException('FOS_L2_ENABLED must be 0 or 1.'),
        };
        $integer = static function (string $key, int $default) use ($environment): int {
            $value = $environment[$key] ?? (string)$default;
            if (!preg_match('/^[1-9][0-9]{0,8}$/D', $value)) {
                throw new DomainException('Invalid L2 integer setting: '.$key);
            }
            return (int)$value;
        };
        return new self(
            new L2SubscriptionConfig(
                $environment['FOS_L2_VENUE'],
                $environment['FOS_L2_SYMBOL'],
                $integer('FOS_L2_DEPTH',50),
            ),
            $integer('FOS_L2_MESSAGE_BUDGET',1000),
            $integer('FOS_L2_MAX_ATTEMPTS',20),
            $integer('FOS_L2_STALE_SECONDS',30),
            $enabled,
        );
    }
}
