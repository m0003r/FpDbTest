<?php

namespace FpDbTest\Tests;

use FpDbTest\DatabaseInterface;
use mysqli;
use PHPUnit\Framework\TestCase;

abstract class AbstractDatabaseTestCase extends TestCase
{
    const SKIP = '__SKIP__';
    protected static mysqli $mysqli;

    public static function setUpBeforeClass(): void
    {
        self::$mysqli = MysqliFactory::createMysqli();
    }

    abstract public function getDatabase(mysqli $mysqli): DatabaseInterface;

    public static function replaceSkip(array $args, mixed $skip): array
    {
        return array_map(function ($arg) use ($skip) {
            if (is_array($arg)) {
                return self::replaceSkip($arg, $skip);
            }
            return $arg === self::SKIP ? $skip : $arg;
        }, $args);
    }

    /**
     * @dataProvider successQueryProvider
     */
    public function testBuildQuery(string $query, array $args, string $expected): void
    {
        $db = $this->getDatabase(self::$mysqli);

        $args = self::replaceSkip($args, $db->skip());
        $result = $db->buildQuery($query, $args);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider failQueryProvider
     */
    public function testFailBuildQuery(string $query, array $args): void
    {
        $db = $this->getDatabase(self::$mysqli);
        $this->expectException(\Throwable::class);
        $args = self::replaceSkip($args, $db->skip());
        $result = $db->buildQuery($query, $args);
        $this->fail('Expected exception, got: "' . $result . '"');
    }

    public static function successQueryProvider()
    {
        yield from self::originalTests();
        yield from self::castTests();
        yield from self::conditionalTests();
    }

    public static function failQueryProvider()
    {
        yield from self::argumentCountFails();
        yield from self::castFails();
        yield from self::conditionalFails();
    }

    public static function argumentCountFails()
    {
        yield 'Missing argument' => [
            'SELECT * FROM table WHERE id = ?d AND name = ?',
            [1],
        ];

        yield 'Extra argument' => [
            'SELECT * FROM table WHERE id = ?d AND name = ?',
            [1, 'test', 'extra'],
        ];
    }

    public static function castTests()
    {
        /// region unspecifed type casts
        yield 'Unspecified string' => [
            '?',
            ['test'],
            "'test'"
        ];

        yield 'Escape string' => [
            '?',
            ["'test'"],
            "'\\'test\\''"
        ];

        yield 'Unspecified int' => [
            '?',
            [1],
            '1'
        ];

        yield 'Unspecified float' => [
            '?',
            [1.1],
            '1.1'
        ];

        yield 'Unspecified bool (true)' => [
            '?',
            [true],
            '1'
        ];

        yield 'Unspecified bool (false)' => [
            '?',
            [false],
            '0'
        ];

        yield 'Unspecified null' => [
            '?',
            [null],
            'NULL'
        ];
        ///endregion

        /// region cast to int
        yield 'Cast int' => [
            '?d',
            [1],
            '1'
        ];

        yield 'Cast float to int' => [
            '?d',
            [1.1],
            '1'
        ];

        yield 'Cast string to int' => [
            '?d',
            ['1'],
            '1'
        ];

        yield 'Cast bool to int (true)' => [
            '?d',
            [true],
            '1'
        ];

        yield 'Cast bool to int (false)' => [
            '?d',
            [false],
            '0'
        ];

        yield 'Cast null to int' => [
            '?d',
            [null],
            'NULL'
        ];

        yield 'Cast anything to int' => [
            '?d ?d ?d',
            ['test', [], simplexml_load_string('<xml>31337</xml>')],
            '0 0 31337'
        ];
        /// endregion

        /// region cast to float
        yield 'Cast float' => [
            '?f',
            [1.1],
            '1.1'
        ];

        yield 'Cast int to float' => [
            '?f',
            [1],
            '1'
        ];

        yield 'Cast string to float' => [
            '?f',
            ['1.1'],
            '1.1'
        ];

        yield 'Cast bool to float (true)' => [
            '?f',
            [true],
            '1'
        ];

        yield 'Cast bool to float (false)' => [
            '?f',
            [false],
            '0'
        ];

        yield 'Cast anything to float' => [
            '?f ?f ?f',
            ['test', [], simplexml_load_string('<xml>31337.31337</xml>')],
            '0 0 31337.31337'
        ];
        /// endregion

        /// region cast as array

        yield 'Cast array' => [
            '?a',
            [[1, 2, 3]],
            '1, 2, 3'
        ];

        yield 'Cast empty array' => [
            '?a',
            [[]],
            ''
        ];

        yield 'Cast array with string' => [
            '?a',
            [[1, 'test', 3]],
            '1, \'test\', 3'
        ];

        yield 'Cast array with null' => [
            '?a',
            [[1, null, 3]],
            '1, NULL, 3'
        ];

        yield 'Cast array with different types' => [
            '?a',
            [[1, 1.1, 'test', true, false, null]],
            '1, 1.1, \'test\', 1, 0, NULL'
        ];

        yield 'Cast array with array' => [
            '?a',
            [[1, [1, 2, 3], 3]],
            '1, 1, 2, 3, 3'
        ];

        yield 'Cast associative array' => [
            '?a',
            [['a' => 1, 'b' => 2, 'c' => 3]],
            '`a` = 1, `b` = 2, `c` = 3'
        ];

        yield 'Cast associative array with null' => [
            '?a',
            [['a' => 1, 'b' => null, 'c' => 3]],
            '`a` = 1, `b` = NULL, `c` = 3'
        ];

        /// endregion

        /// region cast as field
        yield 'Cast field' => [
            '?#',
            ['test'],
            '`test`'
        ];

        yield 'Cast field with array' => [
            '?#',
            [['a', 'b', 'c']],
            '`a`, `b`, `c`'
        ];
        /// endregion
    }


    public static function castFails()
    {
        yield 'Bad arg type (object)' => [
            '?',
            [new \stdClass()],
        ];

        yield 'Bad arg type (resource)' => [
            '?',
            [fopen('php://memory', 'r')],
        ];

        /// region unsupported arg types for array
        yield 'Array does not support null' => [
            '?a',
            [null],
        ];

        yield 'Array does not support string' => [
            '?a',
            ['test'],
        ];

        yield 'Array does not support int' => [
            '?a',
            [1],
        ];

        yield 'Array does not support float' => [
            '?a',
            [1.1],
        ];

        yield 'Array does not support bool' => [
            '?a',
            [true],
        ];

        yield 'Bad unspecified arg type (array)' => [
            '?',
            [[]],
        ];
        ///endregion

        /// region unsupported arg types for field
        yield 'Field does not support null' => [
            '?#',
            [null],
        ];

        yield 'Field does not support null (inside array)' => [
            '?#',
            [['a', null, 'c']],
        ];
        /// endregion

        /// region skip values
        yield 'There should be no skip() inside array' => [
            '{ ?a }',
            [[self::SKIP]],
        ];

        yield 'There should be no skip() inside associative array' => [
            '{ ?a }',
            [['a' => self::SKIP]],
        ];

        yield 'There should be no skip() inside field list' => [
            '{ ?# }',
            [[self::SKIP]],
        ];
        /// endregion
    }

    public static function conditionalTests()
    {
        /// region simple conditional test

        yield 'Simple conditional test' => [
            '{ name = ? }',
            [self::SKIP],
            ''
        ];

        yield 'Simple conditional test with value' => [
            '{ name = ? }',
            [null],
            ' name = NULL '
        ];

        yield 'Simple conditional with several params' => [
            'SELECT * FROM table WHERE id = ?d AND name = ?{ AND block = ?d}',
            [1, 'test', self::SKIP],
            'SELECT * FROM table WHERE id = 1 AND name = \'test\''
        ];

        yield 'Simple conditional with several params (with value)' => [
            'SELECT * FROM table WHERE id = ?d AND name = ?{ AND block = ?d}',
            [1, 'test', 1],
            'SELECT * FROM table WHERE id = 1 AND name = \'test\' AND block = 1'
        ];


        /// endregion

        /// region conditional test with multiple values
        yield 'Conditional test with multiple values' => [
            '{ name = ? AND block = ?d }',
            [self::SKIP, 1],
            ''
        ];

        yield 'Conditional test with multiple values (with value)' => [
            '{ name = ? AND block = ?d }',
            ['Jack', 1],
            ' name = \'Jack\' AND block = 1 '
        ];

        yield 'Conditional test with multiple values (with nulls)' => [
            '{ name = ? AND block = ?d }',
            [null, 1],
            ' name = NULL AND block = 1 '
        ];

        yield 'Multiple conditional test with multiple values' => [
            '{ name = ? AND} block = ?d {AND age = ?d }',
            [self::SKIP, 1, 18],
            ' block = 1 AND age = 18 '
        ];
        /// endregion
    }

    public static function conditionalFails()
    {
        yield 'Skip value in array' => [
            '{ ?a }',
            [[0, self::SKIP]],
            ''
        ];

        yield 'Nested conditional test' => [
            '{ name = ?{ AND block = ?d} }',
            [self::SKIP, 1],
        ];

        yield 'Unmatched brace (left)' => [
            '{ name = ?d',
            [1],
        ];

        yield 'Unmatched brace (right)' => [
            'name = ?d}',
            [1],
        ];

        yield 'Unmatched brace (both)' => [
            '{ name = ?d }}',
            [1],
        ];

        yield 'Double open' => [
            '{ { name = ?d }',
            [1],
        ];
    }

    public static function originalTests()
    {
        yield [
            'SELECT * FROM table WHERE id = ?d AND name = ?',
            [1, 'test'],
            'SELECT * FROM table WHERE id = 1 AND name = \'test\''
        ];
        yield [
            'SELECT name FROM users WHERE user_id = 1',
            [],
            'SELECT name FROM users WHERE user_id = 1'
        ];
        yield [
            'SELECT * FROM users WHERE name = ? AND block = 0',
            ['Jack'],
            'SELECT * FROM users WHERE name = \'Jack\' AND block = 0'
        ];

        yield [
            'SELECT ?# FROM users WHERE user_id = ?d AND block = ?d',
            [['name', 'email'], 2, true],
            'SELECT `name`, `email` FROM users WHERE user_id = 2 AND block = 1'
        ];

        yield [
            'UPDATE users SET ?a WHERE user_id = -1',
            [['name' => 'Jack', 'email' => null]],
            'UPDATE users SET `name` = \'Jack\', `email` = NULL WHERE user_id = -1',
        ];

        yield [
            'SELECT name FROM users WHERE ?# IN (?a){ AND block = ?d}',
            ['user_id', [1, 2, 3], true],
            'SELECT name FROM users WHERE `user_id` IN (1, 2, 3) AND block = 1'
        ];

        yield [
            'SELECT name FROM users WHERE ?# IN (?a){ AND block = ?d}',
            ['user_id', [1, 2, 3], "__SKIP__"],
            'SELECT name FROM users WHERE `user_id` IN (1, 2, 3)'
        ];
    }

}