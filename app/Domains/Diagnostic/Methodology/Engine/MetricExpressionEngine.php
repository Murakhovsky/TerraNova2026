<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use DateTimeImmutable;
use InvalidArgumentException;

/** Deterministic arithmetic expression interpreter for methodology metric formulas. */
final class MetricExpressionEngine
{
    /** @var list<array{type:string,value:string}> */
    private array $tokens = [];
    private int $position = 0;
    /** @var array<string,mixed> */
    private array $values = [];

    /** @param array<string,mixed> $values */
    public function evaluate(string $expression, array $values): int|float
    {
        $this->tokens = $this->tokenize($expression);
        $this->position = 0;
        $this->values = $values;
        $result = $this->expression();
        if ($this->position !== count($this->tokens)) throw new InvalidArgumentException('Unexpected token in metric formula.');
        if (is_array($result)) throw new InvalidArgumentException('Metric formula must aggregate collection values.');
        if (!is_int($result) && !is_float($result)) throw new InvalidArgumentException('Metric formula must produce a number.');
        return is_finite((float) $result) ? $result : throw new InvalidArgumentException('Metric formula produced a non-finite number.');
    }

    public function validate(string $expression): void
    {
        $identifiers = [];
        foreach ($this->tokenize($expression) as $token) if ($token['type'] === 'id') $identifiers[$token['value']] = 1;
        $values = array_fill_keys(array_keys($identifiers), 1.0);
        foreach (['avg','average','median','sum','count','min','max','ratio'] as $function) unset($values[$function]);
        $this->evaluate($expression, $values);
    }

    /** @return list<array{type:string,value:string}> */
    private function tokenize(string $expression): array
    {
        if (trim($expression) === '' || strlen($expression) > 1000) throw new InvalidArgumentException('Metric formula is empty or too long.');
        preg_match_all('/\G\s*(?:(?<number>\d+(?:\.\d+)?)|(?<id>[A-Za-z_][A-Za-z0-9_.]*)|(?<op>[+\-*\/,()]))/A', $expression, $matches, PREG_SET_ORDER);
        $tokens = []; $consumed = '';
        foreach ($matches as $match) {
            $consumed .= $match[0];
            $tokens[] = isset($match['number']) && $match['number'] !== ''
                ? ['type' => 'number', 'value' => $match['number']]
                : (isset($match['id']) && $match['id'] !== '' ? ['type' => 'id', 'value' => $match['id']] : ['type' => 'op', 'value' => $match['op']]);
        }
        if (trim($consumed) !== trim($expression)) throw new InvalidArgumentException('Unsupported syntax in metric formula.');
        return $tokens;
    }

    private function expression(): mixed
    {
        $value = $this->term();
        while ($this->peek('+') || $this->peek('-')) {$operator = $this->next()['value']; $value = $this->binary($value, $this->term(), $operator);}
        return $value;
    }

    private function term(): mixed
    {
        $value = $this->factor();
        while ($this->peek('*') || $this->peek('/')) {$operator = $this->next()['value']; $value = $this->binary($value, $this->factor(), $operator);}
        return $value;
    }

    private function factor(): mixed
    {
        if ($this->peek('-')) {$this->next(); return $this->binary(0, $this->factor(), '-');}
        $token = $this->next();
        if ($token === null) throw new InvalidArgumentException('Unexpected end of metric formula.');
        if ($token['type'] === 'number') return (float) $token['value'];
        if ($token['type'] === 'op' && $token['value'] === '(') {$value = $this->expression(); $this->expect(')'); return $value;}
        if ($token['type'] !== 'id') throw new InvalidArgumentException('Expected a number, fact, metric or function.');
        if ($this->peek('(')) return $this->function($token['value']);
        if (!array_key_exists($token['value'], $this->values)) throw new InvalidArgumentException('Metric formula input is missing: ' . $token['value']);
        return $this->normalize($this->values[$token['value']]);
    }

    private function function(string $name): int|float
    {
        $this->expect('('); $arguments = [];
        if (!$this->peek(')')) {do {$arguments[] = $this->expression(); if (!$this->peek(',')) break; $this->next();} while (true);}
        $this->expect(')');
        $flat = [];
        foreach ($arguments as $argument) foreach ((array) $argument as $value) $flat[] = $this->number($value);
        return match (strtolower($name)) {
            'avg', 'average' => $flat !== [] ? array_sum($flat) / count($flat) : throw new InvalidArgumentException('avg() needs values.'),
            'sum' => array_sum($flat),
            'count' => count($flat),
            'min' => $flat !== [] ? min($flat) : throw new InvalidArgumentException('min() needs values.'),
            'max' => $flat !== [] ? max($flat) : throw new InvalidArgumentException('max() needs values.'),
            'median' => $this->median($flat),
            'ratio' => count($flat) === 2 && $flat[1] != 0.0 ? $flat[0] / $flat[1] : throw new InvalidArgumentException('ratio() needs two values and a non-zero denominator.'),
            default => throw new InvalidArgumentException('Unsupported metric formula function: ' . $name),
        };
    }

    private function binary(mixed $left, mixed $right, string $operator): mixed
    {
        if (is_array($left) || is_array($right)) {
            $a = is_array($left) ? array_values($left) : array_fill(0, count($right), $left);
            $b = is_array($right) ? array_values($right) : array_fill(0, count($left), $right);
            if (count($a) !== count($b)) throw new InvalidArgumentException('Metric formula collections must have equal sizes.');
            return array_map(fn ($x, $y) => $this->binary($x, $y, $operator), $a, $b);
        }
        $a = $this->number($left); $b = $this->number($right);
        return match ($operator) {'+' => $a + $b, '-' => $a - $b, '*' => $a * $b, '/' => $b != 0.0 ? $a / $b : throw new InvalidArgumentException('Division by zero in metric formula.'), default => throw new InvalidArgumentException('Unsupported arithmetic operator.')};
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) return array_map(fn ($item) => $this->normalize($item), $value);
        if (is_numeric($value)) return (float) $value;
        if (is_string($value)) {try {return (float) (new DateTimeImmutable($value))->format('U');} catch (\Throwable) {}}
        throw new InvalidArgumentException('Metric formula inputs must be numeric, date/time, or collections thereof.');
    }

    private function number(mixed $value): float {return is_numeric($value) ? (float) $value : throw new InvalidArgumentException('Metric formula operand is not numeric.');}
    /** @param list<float> $values */
    private function median(array $values): float {if ($values === []) throw new InvalidArgumentException('median() needs values.'); sort($values, SORT_NUMERIC); $middle = intdiv(count($values), 2); return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;}
    private function peek(string $value): bool {return isset($this->tokens[$this->position]) && $this->tokens[$this->position]['value'] === $value;}
    /** @return array{type:string,value:string}|null */
    private function next(): ?array {return $this->tokens[$this->position++] ?? null;}
    private function expect(string $value): void {if (!$this->peek($value)) throw new InvalidArgumentException('Expected "' . $value . '" in metric formula.'); $this->next();}
}
