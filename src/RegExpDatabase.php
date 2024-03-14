<?php

namespace FpDbTest;

use mysqli;

class RegExpDatabase implements DatabaseInterface
{
    private mysqli $mysqli;
    private object $skip;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->skip = new class {
        };
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $index = 0;
        $argCount = count($args);
        $formattedQuery = preg_replace_callback('/\?([dfa#])?/', function ($matches) use (&$index, $args, $argCount) {
            if ($index >= $argCount) {
                throw new \Exception('Missing argument for placeholder');
            }

            $value = $args[$index++];
            if ($value === $this->skip) {
                return '?'; // should never be in formatted query, so it's safe to use it
            }
            $type = $matches[1] ?? null;

            if ($value === null) {
                if ($type === 'a' || $type === '#') {
                    throw new \Exception('Array and field types cannot be null');
                }
                return 'NULL';
            }

            switch ($type) {
                case 'd':
                    return (int)$value;
                case 'f':
                    return (float)$value;
                case '#':
                    return '`' . implode('`, `',
                            array_map(static fn($x) => is_string($x) ?
                                $x : throw new \Exception('Field name must be a string'),
                                (array)$value)) . '`';
                case 'a':
                    if (!is_array($value)) {
                        throw new \Exception('Array argument must have a type ?a');
                    }
                    return $this->formatValue($value);
                /** @noinspection PhpMissingBreakStatementInspection */
                case null:
                    if (is_array($value)) {
                        throw new \Exception('Array argument must have a type ?a');
                    }
                // дальше идёт обработка как для ?, break не нужен
                default:
                    return $this->formatValue($value);
            }
        }, $query);

        // Handle conditional blocks
        $formattedQuery = preg_replace_callback('/\{(.*?)}/', function ($matches) {
            if (str_contains($matches[1], '?')) {
                return '';
            }
            return $matches[1];
        }, $formattedQuery);

        // check if there is no unmatched placeholders
        if (preg_match('/[?{}]/', $formattedQuery)) {
            throw new \Exception('Unmatched placeholders and/or braces');
        }

        if ($index < $argCount) {
            throw new \Exception('Too many arguments for placeholders');
        }

        return $formattedQuery;
    }

    private function formatValue($value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value)) {
            return "'" . $this->mysqli->real_escape_string($value) . "'";
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return implode(', ', array_map(function ($item) {
                    return $this->formatValue($item);
                }, $value));
            }

            return implode(', ', array_map(function ($key, $value) {
                return '`' . $key . '` = ' . $this->formatValue($value);
            }, array_keys($value), $value));
        }

        throw new \Exception('Unsupported value type: ' . gettype($value));
    }

    public function skip()
    {
        return $this->skip;
    }
}
