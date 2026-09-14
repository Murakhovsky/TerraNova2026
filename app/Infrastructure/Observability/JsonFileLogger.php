<?php
declare(strict_types=1);

namespace Infrastructure\Observability;

use DateTimeImmutable;
use Kernel\Observability\StructuredLoggerInterface;

final readonly class JsonFileLogger implements StructuredLoggerInterface
{
    public function __construct(private string $file)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory)) mkdir($directory, 0775, true);
        $entry = json_encode([
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $this->sanitize($context),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function sanitize(array $context): array
    {
        $sensitive = ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key'];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitize($value);
            } elseif (is_string($value) && strlen($value) > 4000) {
                $context[$key] = substr($value, 0, 4000) . '…';
            }
        }
        return $context;
    }
}
