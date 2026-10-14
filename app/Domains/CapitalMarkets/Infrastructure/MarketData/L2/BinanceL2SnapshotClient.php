<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Bounded HTTPS snapshot fetch. Never follows redirects or disables TLS checks.
 * Snapshot is decoded only after full response size validation.
 */
final readonly class BinanceL2SnapshotClient
{
    public function __construct(
        private BinanceL2SnapshotDecoder $decoder,
        private int $timeoutSeconds = 5,
        private int $maxBytes = 2097152,
    ) {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30 || $maxBytes < 1024 || $maxBytes > 16777216) {
            throw new DomainException('Invalid Binance snapshot transport limits.');
        }
    }

    /** @return array{sequence:string,bids:array,asks:array} */
    public function fetch(L2SubscriptionConfig $config): array
    {
        $url = $config->snapshotUrl();
        if ($url === null) throw new DomainException('Venue has no Binance REST snapshot endpoint.');
        $context = stream_context_create(['http'=>[
            'method'=>'GET', 'timeout'=>$this->timeoutSeconds,
            'follow_location'=>0, 'max_redirects'=>0,
            'header'=>"Accept: application/json\r\nConnection: close\r\n",
            'ignore_errors'=>true,
        ], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) throw new DomainException('Binance snapshot connection failed.');
        try {
            $metadata = stream_get_meta_data($stream);
            $headers = $metadata['wrapper_data'] ?? [];
            $status = is_array($headers) ? ($headers[0] ?? '') : '';
            if (!preg_match('/^HTTP\/\S+ 200(?:\s|$)/', $status)) throw new DomainException('Binance snapshot HTTP status not 200.');
            $body = stream_get_contents($stream, $this->maxBytes + 1);
            if ($body === false || strlen($body) > $this->maxBytes) throw new DomainException('Binance snapshot response exceeded limit.');
            return $this->decoder->decode($body);
        } finally {
            fclose($stream);
        }
    }
}
