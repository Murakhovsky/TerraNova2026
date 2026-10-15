<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Bounded TLS snapshot source. HTTPS is primary; if Futures REST is explicitly
 * region-denied (403/451), try Binance's official *public* Futures WS depth RPC.
 * Never fallback on throttle (429), bad payload, TLS failure, or other errors.
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
        if ($url === null) throw new DomainException('Venue has no Binance snapshot endpoint.');
        $context = stream_context_create([
            'http'=>[
                'method'=>'GET',
                'timeout'=>$this->timeoutSeconds,
                'follow_location'=>0,
                'max_redirects'=>0,
                'header'=>"Accept: application/json\r\nConnection: close\r\n",
                'ignore_errors'=>true,
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) throw new DomainException('Binance snapshot TLS/HTTP connection failed.');
        $statusCode = null;
        try {
            $metadata = stream_get_meta_data($stream);
            $headers = $metadata['wrapper_data'] ?? [];
            $status = is_array($headers) ? ($headers[0] ?? '') : '';
            $statusCode = preg_match('/^HTTP\/\S+ ([0-9]{3})(?:\s|$)/', $status, $match)
                ? (int) $match[1] : 0;
            if ($statusCode === 200) {
                $body = stream_get_contents($stream, $this->maxBytes + 1);
                if ($body === false || strlen($body) > $this->maxBytes) {
                    throw new DomainException('Binance snapshot response exceeded limit.');
                }
                return $this->decoder->decode($body);
            }
        } finally {
            fclose($stream);
        }
        if ($config->venue === 'BINANCE_FUTURES' && in_array($statusCode, [403,451], true)) {
            // Public market-data method; no user account, keys or trading actions.
            try {
                return $this->fetchFuturesWebSocketSnapshot($config);
            } catch (\Throwable $failure) {
                throw new DomainException(
                    'Binance Futures REST returned HTTP '.$statusCode.' and public WS snapshot failed.',
                    0, $failure,
                );
            }
        }
        throw new DomainException('Binance snapshot HTTP status '.($statusCode ?: 'unknown').'; expected 200.');
    }

    /** @return array{sequence:string,bids:array,asks:array} */
    private function fetchFuturesWebSocketSnapshot(L2SubscriptionConfig $config): array
    {
        $socket = new L2WebSocketTransport($this->timeoutSeconds, $this->maxBytes);
        try {
            $socket->connect('wss://ws-fapi.binance.com/ws-fapi/v1');
            $id = 'l2-'.bin2hex(random_bytes(10));
            $socket->sendText(json_encode([
                'id'=>$id,
                'method'=>'depth',
                'params'=>['symbol'=>$config->symbol,'limit'=>1000],
            ], JSON_THROW_ON_ERROR));
            return $this->decoder->decodeFuturesWebSocketDepth($socket->readText(), $id);
        } finally {
            $socket->close();
        }
    }
}
