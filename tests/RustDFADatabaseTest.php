<?php

namespace FpDbTest\Tests;

use FpDbTest\DatabaseInterface;

class RustDFADatabaseTest extends \FpDbTest\Tests\AbstractDatabaseTestCase
{

    public function getDatabase(\mysqli $mysqli): DatabaseInterface
    {
        return new \FpDbTest\RustDFADatabase($mysqli);
    }
}