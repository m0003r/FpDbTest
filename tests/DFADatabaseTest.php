<?php

use FpDbTest\DatabaseInterface;

class DFADatabaseTest extends \FpDbTest\Tests\AbstractDatabaseTestCase
{

    public function getDatabase(\mysqli $mysqli): DatabaseInterface
    {
        return new \FpDbTest\DFADatabase($mysqli);
    }
}