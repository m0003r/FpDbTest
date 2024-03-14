<?php

use FpDbTest\DatabaseTest;
use FpDbTest\Database;

spl_autoload_register(function ($class) {
    $a = array_slice(explode('\\', $class), 1);
    if (!$a) {
        throw new Exception();
    }
    $filename = implode('/', [__DIR__, ...$a]) . '.php';
    require_once $filename;
});

$mysqli = @new mysqli('db', 'root', 'password', 'database', 3306);
if ($mysqli->connect_errno) {
    throw new Exception($mysqli->connect_error);
}

$testClass = "FpDbTest\\" . $argv[1] . "Database";
if (!class_exists($testClass)) {
    throw new Exception("Class $testClass not found");
}

$db = new $testClass($mysqli);
$test = new DatabaseTest($db);
$test->testBuildQuery();

exit('OK');
