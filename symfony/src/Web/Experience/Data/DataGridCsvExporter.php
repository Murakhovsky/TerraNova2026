<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

use InvalidArgumentException;

final class DataGridCsvExporter
{
    /**
     * @param list<DataGridColumn> $columns
     * @param list<array<string,mixed>> $rows
     */
    public function export(array $columns, array $rows): string
    {
        if ($columns === []) {
            throw new InvalidArgumentException('CSV export requires at least one column.');
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new InvalidArgumentException('CSV export stream could not be opened.');
        }

        fputcsv($stream, array_map(
            static fn (DataGridColumn $column): string => $column->label,
            $columns,
        ));

        foreach ($rows as $row) {
            $values = [];

            foreach ($columns as $column) {
                $value = $row;
                foreach (explode('.', $column->key) as $segment) {
                    if (!is_array($value) || !array_key_exists($segment, $value)) {
                        $value = '';
                        break;
                    }
                    $value = $value[$segment];
                }

                if (is_bool($value)) {
                    $values[] = $value ? '1' : '0';
                } elseif (is_scalar($value) || $value === null) {
                    $values[] = (string) ($value ?? '');
                } else {
                    $values[] = '';
                }
            }

            fputcsv($stream, $values);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new InvalidArgumentException('CSV export could not be read.');
        }

        return "\xEF\xBB\xBF" . $contents;
    }
}
