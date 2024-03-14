<?php

namespace FpDbTest;

use Exception;
use mysqli;

class DFADatabase implements DatabaseInterface
{
    private object $skipValue;

    public function __construct(private readonly mysqli $mysqli)
    {
        $this->skipValue = new class {
        };
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $totalArgs = count($args);
        $currentArg = 0;

        // выходные части строки
        $outputParts = [];

        // вложенный блок
        $conditionalBlock = [];
        $insideConditional = false;
        $renderConditional = true;

        // куда писать текущий фрагмент строки — в основной блок (или во вложненный)
        $currentOutput = &$outputParts;

        $queryLength = strlen($query); // длина строки запроса
        $i = 0; // текущий символ
        $r = 0; // сколько символов можно просто скопировать, как единую строку в текущий блок

        goto start;

        next:
        $i++;
        if ($i >= $queryLength) {
            goto end;
        }

        start:
        $c = $query[$i];

        if ($c === '{') {
            if ($insideConditional) {
                throw new Exception('Nested conditional blocks are not supported at position ' . $i);
            }
            if ($r > 0) {
                $currentOutput[] = substr($query, $i - $r, $r);
                $r = 0;
            }

            $insideConditional = true;
            $renderConditional = true;
            $conditionalBlock = [];
            $currentOutput = &$conditionalBlock;
            goto next;
        }

        if ($c === '}') {
            if (!$insideConditional) {
                throw new Exception('Unmatched } at position ' . $i);
            }
            if ($r > 0) {
                $currentOutput[] = substr($query, $i - $r, $r);
                $r = 0;
            }
            $insideConditional = false;
            $currentOutput = &$outputParts;
            $outputParts[] = $renderConditional ? implode('', $conditionalBlock) : '';
            goto next;
        }

        if ($c !== '?') {
            $r++;
            goto next;
        }

        if ($r > 0) {
            $currentOutput[] = substr($query, $i - $r, $r);
            $r = 0;
        }

        if ($currentArg >= $totalArgs) {
            throw new Exception('No arguments for ? at position ' . $i);
        }
        $arg = $args[$currentArg];
        $currentArg++;

        if ($arg === $this->skipValue) {
            if ($insideConditional) {
                $renderConditional = false;
                goto next;
            }
            throw new Exception('Cannot skip non-conditional argument at position ' . $i);
        }

        $i++;
        if ($i === $queryLength) {
            goto unspecified_arg;
        }

        $nextChar = $query[$i];
        switch ($nextChar) {
            case 'd':
                goto int_arg;
            case 'f':
                goto float_arg;
            case 'a':
                goto array_arg;
            case '#':
                goto field_arg;
            default:
                $r++;
                goto unspecified_arg;
        }

        unspecified_arg:
        if (is_array($arg)) {
            throw new Exception('Argument for ? at position ' . $i . ' is an array, but no ?a was specified');
        }

        $currentOutput[] = $this->formatArg($arg);

        goto next;

        int_arg:
        if ($arg === null) {
            $currentOutput[] = 'NULL';
            goto next;
        }

        $currentOutput[] = (int)$arg;
        goto next;

        float_arg:
        if ($arg === null) {
            $currentOutput[] = 'NULL';
            goto next;
        }

        $currentOutput[] = (float)$arg;
        goto next;

        array_arg:
        if (!is_array($arg)) {
            throw new Exception('Argument for ?a at position ' . $i . ' is not an array');
        }
        $currentOutput[] = $this->formatArg($arg);
        goto next;

        field_arg:
        if (is_array($arg)) {
            $buff = [];
            foreach ($arg as $item) {
                if (!is_string($item)) {
                    throw new Exception('Argument for ?# at position ' . $i . ' is not a string');
                }
                $buff[] = "`$item`";
            }
            $currentOutput[] = implode(', ', $buff);
            goto next;
        }

        if (!is_string($arg)) {
            throw new Exception('Argument for ?# at position ' . $i . ' is not a string');
        }
        $currentOutput[] = "`$arg`";
        goto next;

        end:
        if ($insideConditional) {
            throw new Exception('Unmatched {');
        }
        if ($currentArg < $totalArgs) {
            throw new Exception('Too many arguments');
        }
        if ($r > 0) {
            $outputParts[] = substr($query, $i - $r, $r);
        }
        return implode('', $outputParts);
    }

    private function formatArg(string|int|float|bool|null|array $arg): string
    {
        if (is_int($arg) || is_float($arg)) {
            return (string)$arg;
        }
        if (is_bool($arg)) {
            return $arg ? '1' : '0';
        }
        if (is_string($arg)) {
            return "'" . $this->mysqli->real_escape_string($arg) . "'";
        }
        if ($arg === null) {
            return 'NULL';
        }
        if (array_is_list($arg)) {
            $buffer = [];
            foreach ($arg as $item) {
                if ($item === $this->skipValue) {
                    throw new Exception('Array values cannot be skip()');
                }

                $buffer[] = $this->formatArg($item);
            }
            return implode(', ', $buffer);
        }
        $buffer = [];
        foreach ($arg as $key => $value) {
            if ($value === $this->skipValue) {
                throw new Exception('Array values cannot be skip()');
            }
            $buffer[] = "`$key` = " . $this->formatArg($value);
        }
        return implode(', ', $buffer);
    }

    public function skip()
    {
        return $this->skipValue;
    }
}
