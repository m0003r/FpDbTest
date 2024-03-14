<?php

namespace FpDbTest\Tests;

use mysqli;

class MysqliFactory
{
    private static $mysqli;

    public static function createMysqli(): \mysqli
    {
        if (self::$mysqli === null) {
            self::$mysqli = new mysqli('db', 'root', 'password', 'database');
        }

        return self::$mysqli;
    }
}