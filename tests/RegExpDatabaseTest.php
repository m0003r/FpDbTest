<?php

namespace FpDbTest\Tests;
use FpDbTest\DatabaseInterface;

class RegExpDatabaseTest extends AbstractDatabaseTestCase
{

    public function getDatabase(\mysqli $mysqli): DatabaseInterface
    {
        return new \FpDbTest\RegExpDatabase($mysqli);
    }
}