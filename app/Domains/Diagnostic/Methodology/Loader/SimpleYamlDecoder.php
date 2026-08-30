<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Loader;

use InvalidArgumentException;

/**
 * Dependency-free decoder for the data-only YAML subset used by diagnostic packs.
 * It deliberately rejects tabs, anchors, tags and executable/custom YAML types.
 */
final class SimpleYamlDecoder
{
    /** @var list<array{indent:int,text:string,line:int}> */
    private array $lines = [];
    private int $position = 0;

    /** @return array<string,mixed> */
    public function decode(string $yaml): array
    {
        $this->lines = [];
        $this->position = 0;
        foreach (preg_split('/\R/', $yaml) ?: [] as $index => $raw) {
            if (str_contains($raw, "\t")) throw new InvalidArgumentException('YAML tabs are not supported (line ' . ($index + 1) . ').');
            $text = $this->stripComment(rtrim($raw));
            if (trim($text) === '' || trim($text) === '---' || trim($text) === '...') continue;
            if (preg_match('/(^|\s)[&*!][a-zA-Z0-9_-]+/', $text)) {
                throw new InvalidArgumentException('YAML anchors, aliases and tags are not allowed (line ' . ($index + 1) . ').');
            }
            $indent = strlen($text) - strlen(ltrim($text, ' '));
            $this->lines[] = ['indent' => $indent, 'text' => ltrim($text, ' '), 'line' => $index + 1];
        }
        if ($this->lines === []) throw new InvalidArgumentException('Diagnostic pack YAML is empty.');
        $decoded = $this->parseBlock($this->lines[0]['indent']);
        if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('Diagnostic pack YAML root must be a mapping.');
        return $decoded;
    }

    private function parseBlock(int $indent): array
    {
        $line = $this->lines[$this->position] ?? null;
        if ($line === null || $line['indent'] !== $indent) throw new InvalidArgumentException('Invalid YAML indentation.');
        return str_starts_with($line['text'], '- ') || $line['text'] === '-'
            ? $this->parseList($indent)
            : $this->parseMap($indent);
    }

    private function parseMap(int $indent): array
    {
        $result = [];
        while (($line = $this->lines[$this->position] ?? null) !== null && $line['indent'] === $indent && !str_starts_with($line['text'], '-')) {
            [$key, $rest] = $this->splitPair($line['text'], $line['line']);
            if (array_key_exists($key, $result)) throw new InvalidArgumentException(sprintf('Duplicate YAML key "%s" on line %d.', $key, $line['line']));
            $this->position++;
            $result[$key] = $rest === '' ? $this->nestedValue($indent) : $this->scalar($rest, $line['line']);
        }
        return $result;
    }

    private function parseList(int $indent): array
    {
        $result = [];
        while (($line = $this->lines[$this->position] ?? null) !== null && $line['indent'] === $indent && str_starts_with($line['text'], '-')) {
            $rest = trim(substr($line['text'], 1));
            $this->position++;
            if ($rest === '') {
                $result[] = $this->nestedValue($indent);
                continue;
            }
            if ($this->hasMappingSeparator($rest)) {
                [$key, $value] = $this->splitPair($rest, $line['line']);
                $item = [$key => $value === '' ? $this->nestedValue($indent) : $this->scalar($value, $line['line'])];
                $next = $this->lines[$this->position] ?? null;
                if ($next !== null && $next['indent'] > $indent) {
                    $continuation = $this->parseBlock($next['indent']);
                    if (array_is_list($continuation)) throw new InvalidArgumentException('A YAML list item mapping cannot be continued by an unkeyed list.');
                    foreach ($continuation as $childKey => $childValue) {
                        if (array_key_exists($childKey, $item)) throw new InvalidArgumentException('Duplicate YAML mapping key: ' . $childKey);
                        $item[$childKey] = $childValue;
                    }
                }
                $result[] = $item;
                continue;
            }
            $result[] = $this->scalar($rest, $line['line']);
        }
        return $result;
    }

    private function nestedValue(int $parentIndent): mixed
    {
        $next = $this->lines[$this->position] ?? null;
        if ($next === null || $next['indent'] <= $parentIndent) return null;
        return $this->parseBlock($next['indent']);
    }

    /** @return array{string,string} */
    private function splitPair(string $text, int $line): array
    {
        $quote = null; $depth = 0;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if (($char === '"' || $char === "'") && ($i === 0 || $text[$i - 1] !== '\\')) $quote = $quote === null ? $char : ($quote === $char ? null : $quote);
            if ($quote !== null) continue;
            if ($char === '[' || $char === '{') $depth++;
            elseif ($char === ']' || $char === '}') $depth--;
            elseif ($char === ':' && $depth === 0) {
                $key = trim(substr($text, 0, $i));
                if ($key === '') throw new InvalidArgumentException('Empty YAML key on line ' . $line . '.');
                return [$this->unquote($key), trim(substr($text, $i + 1))];
            }
        }
        throw new InvalidArgumentException('Expected YAML mapping on line ' . $line . '.');
    }

    private function scalar(string $value, int $line): mixed
    {
        $value = trim($value);
        if ($value === '') return '';
        if (($value[0] === '[' && str_ends_with($value, ']')) || ($value[0] === '{' && str_ends_with($value, '}'))) {
            $json = preg_replace_callback('/(?<=\[|\{|,)\s*([a-zA-Z_][a-zA-Z0-9_.-]*)\s*(?=[:,\]}])/', static fn (array $m): string => '"' . $m[1] . '"', $value);
            try {
                return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidArgumentException('Invalid inline YAML collection on line ' . $line . '. Use quoted strings or block syntax.');
            }
        }
        if (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'"))) return $this->unquote($value);
        return match (strtolower($value)) {
            'null', '~' => null,
            'true' => true,
            'false' => false,
            default => is_numeric($value) ? ($this->isInteger($value) ? (int) $value : (float) $value) : $value,
        };
    }

    private function stripComment(string $line): string
    {
        $quote = null;
        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            $char = $line[$i];
            if (($char === '"' || $char === "'") && ($i === 0 || $line[$i - 1] !== '\\')) $quote = $quote === null ? $char : ($quote === $char ? null : $quote);
            if ($char === '#' && $quote === null && ($i === 0 || ctype_space($line[$i - 1]))) return rtrim(substr($line, 0, $i));
        }
        return $line;
    }

    private function hasMappingSeparator(string $text): bool
    {
        try { $this->splitPair($text, 0); return true; } catch (InvalidArgumentException) { return false; }
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) return stripcslashes(substr($value, 1, -1));
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) return str_replace("''", "'", substr($value, 1, -1));
        return $value;
    }

    private function isInteger(string $value): bool
    {
        return preg_match('/^[+-]?\d+$/', $value) === 1;
    }
}
