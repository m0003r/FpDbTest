<?php

namespace FpDbTest;

use mysqli;
use PHPUnit\Logging\Exception;

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
        $argIndex = 0;
        $argCount = count($args);
        $insideConditional = false;
        $renderConditional = true;

        $parts = preg_split('/(\?[dfa#]?)|([\\{}])/', $query, flags: PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        $output = [];
        $conditionalOutput = [];
        $currentOutput = &$output;

        foreach ($parts as $part) {
            if ($part === '{') {
                if ($insideConditional) {
                    throw new Exception("Unmatched open brace");
                }
                $insideConditional = true;
                $currentOutput = &$conditionalOutput;
                $renderConditional = true;
                continue;
            }
            if ($part === '}') {
                if (!$insideConditional) {
                    throw new Exception("Unmatched close brace");
                }
                $insideConditional = false;
                $currentOutput = &$output;
                $output[] = $renderConditional ? implode('', $conditionalOutput) : '';
                $conditionalOutput = [];
                continue;
            }

            if ($part[0] !== '?') {
                $currentOutput[] = $part;
                continue;
            }

            if ($argIndex >= $argCount) {
                throw new \Exception('Missing argument for placeholder');
            }

            $value = $args[$argIndex++];
            if ($value === $this->skip) {
                $renderConditional = false;
                continue;
            }
            $type = $part[1] ?? null;

            if ($value === null) {
                if ($type === 'a' || $type === '#') {
                    throw new \Exception('Array and field types cannot be null');
                }
                $currentOutput[] = 'NULL';
                continue;
            }

            switch ($type) {
                case 'd':
                    $currentOutput[] = (int)$value;
                    break;
                case 'f':
                    $currentOutput[] = (float)$value;
                    break;
                case '#':
                    $currentOutput[] = '`' . implode('`, `',
                            array_map(static fn($x) => is_string($x) ?
                                $x : throw new \Exception('Field name must be a string'),
                                (array)$value)) . '`';
                    break;
                case 'a':
                    if (!is_array($value)) {
                        throw new \Exception('Array argument must have a type ?a');
                    }
                    $currentOutput[] = $this->formatValue($value);
                    break;
                /** @noinspection PhpMissingBreakStatementInspection */
                case null:
                    if (is_array($value)) {
                        throw new \Exception('Array argument must have a type ?a');
                    }
                // дальше идёт обработка как для ?, break не нужен
                default:
                    $currentOutput[] = $this->formatValue($value);
            }
        }

        if ($insideConditional) {
            throw new \Exception("Unmatched open brace");
        }

        if ($argIndex < $argCount) {
            throw new \Exception('Too many arguments for placeholders');
        }

        return implode('', $output);
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
