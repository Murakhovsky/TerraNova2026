<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Database\Migration;

final class SqlStatementSplitter
{
    /** @return list<string> */
    public function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote === null && (($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) || $char === '#')) {
                $lineComment = true;
                if ($char === '-') $index++;
                continue;
            }
            if ($quote === null && $char === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $index + 1 < $length) {
                    $buffer .= $sql[++$index];
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $sql[++$index];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') $statements[] = $statement;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $statement = trim($buffer);
        if ($statement !== '') $statements[] = $statement;
        return $statements;
    }
}
