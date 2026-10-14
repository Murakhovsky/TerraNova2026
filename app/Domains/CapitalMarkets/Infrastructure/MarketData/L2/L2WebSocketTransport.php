<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Minimal RFC6455 client transport for public TLS market-data streams.
 * One instance owns one connection. Caller handles reconnect and recovery.
 */
class L2WebSocketTransport
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(private readonly int $timeoutSeconds = 10, private readonly int $maxMessageBytes = 2097152)
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 60 || $maxMessageBytes < 1024 || $maxMessageBytes > 16777216) {
            throw new DomainException('Invalid websocket transport limits.');
        }
    }

    public function connect(string $url): void
    {
        if ($this->stream !== null) throw new DomainException('Websocket already connected.');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'wss' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new DomainException('Only credential-free wss URLs are permitted.');
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? 443;
        if ($port < 1 || $port > 65535) throw new DomainException('Invalid websocket port.');
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $context = stream_context_create(['ssl'=>[
            'verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true,
        ]]);
        $stream = @stream_socket_client('tls://'.$host.':'.$port, $errno, $errstr, $this->timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) throw new DomainException('Websocket TLS connection failed.');
        stream_set_timeout($stream, $this->timeoutSeconds);
        try {
            $key = base64_encode(random_bytes(16));
            $hostHeader = $host.($port === 443 ? '' : ':'.$port);
            $request = "GET ".$path." HTTP/1.1\r\nHost: ".$hostHeader."\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".$key."\r\nSec-WebSocket-Version: 13\r\n\r\n";
            $this->writeAll($stream, $request);
            $headers = '';
            while (!str_contains($headers, "\r\n\r\n")) {
                $chunk = fread($stream, 1);
                if ($chunk === false || $chunk === '') throw new DomainException('Websocket handshake timeout.');
                $headers .= $chunk;
                if (strlen($headers) > 16384) throw new DomainException('Websocket handshake too large.');
            }
            [$status] = explode("\r\n", $headers, 2);
            if (!preg_match('/^HTTP\/1\.[01] 101(?:\s|$)/', $status)) throw new DomainException('Websocket upgrade rejected.');
            if (!preg_match('/^Sec-WebSocket-Accept:\s*(.+)\r?$/mi', $headers, $match)
                || !hash_equals(base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), trim($match[1]))) {
                throw new DomainException('Websocket handshake signature invalid.');
            }
            if (!preg_match('/^Upgrade:\s*websocket\s*$/mi', $headers)
                || !preg_match('/^Connection:.*\bUpgrade\b/im', $headers)) {
                throw new DomainException('Websocket upgrade headers invalid.');
            }
            $this->stream = $stream;
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    public function sendText(string $payload): void { $this->sendFrame(1, $payload); }

    /** Returns next complete text message, responding to ping frames. */
    public function readText(): string
    {
        if ($this->stream === null) throw new DomainException('Websocket disconnected.');
        $message = '';
        $started = false;
        while (true) {
            $head = $this->readExact(2);
            $first = ord($head[0]); $second = ord($head[1]);
            $fin = ($first & 0x80) !== 0;
            $opcode = $first & 0x0f;
            if (($first & 0x70) !== 0 || ($second & 0x80) !== 0) throw new DomainException('Invalid server websocket frame.');
            $length = $second & 0x7f;
            if ($length === 126) $length = unpack('n', $this->readExact(2))[1];
            if ($length === 127) {
                $parts = unpack('Nhigh/Nlow', $this->readExact(8));
                if ($parts['high'] !== 0) throw new DomainException('Websocket message exceeds size limit.');
                $length = $parts['low'];
            }
            if ($length > $this->maxMessageBytes || strlen($message) > $this->maxMessageBytes - $length) {
                throw new DomainException('Websocket message exceeds size limit.');
            }
            if ($opcode >= 8 && (!$fin || $length > 125)) throw new DomainException('Invalid websocket control frame.');
            $payload = $this->readExact($length);
            if ($opcode === 8) throw new DomainException('Websocket closed by peer.');
            if ($opcode === 9) { $this->sendFrame(10, $payload); continue; }
            if ($opcode === 10) continue;
            if ($opcode === 1 && !$started) $started = true;
            elseif ($opcode !== 0 || !$started) throw new DomainException('Unexpected websocket opcode.');
            $message .= $payload;
            if ($fin) {
                if (preg_match('//u', $message) !== 1) throw new DomainException('Invalid websocket UTF-8.');
                return $message;
            }
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        if ($this->stream === null) throw new DomainException('Websocket disconnected.');
        $length = strlen($payload);
        if ($length > $this->maxMessageBytes) throw new DomainException('Outbound websocket frame too large.');
        $header = chr(0x80 | $opcode);
        if ($length < 126) $header .= chr(0x80 | $length);
        elseif ($length <= 65535) $header .= chr(0x80 | 126).pack('n', $length);
        else $header .= chr(0x80 | 127).pack('NN', 0, $length);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $length; ++$i) $masked .= $payload[$i] ^ $mask[$i % 4];
        $this->writeAll($this->stream, $header.$mask.$masked);
    }

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($this->stream, $length - strlen($data));
            if ($part === false || $part === '') throw new DomainException('Websocket read failed or timed out.');
            $data .= $part;
        }
        return $data;
    }

    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) throw new DomainException('Websocket write failed.');
            $offset += $written;
        }
    }
}
