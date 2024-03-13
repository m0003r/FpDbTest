<?php

use FpDbTest\Database;
use FpDbTest\DatabaseTest;

spl_autoload_register(function ($class) {
    $a = array_slice(explode('\\', $class), 1);
    if (!$a) {
        throw new Exception();
    }
    $filename = implode('/', [__DIR__, ...$a]) . '.php';
    require_once $filename;
});

// check if mysqli is available
if (!function_exists('mysqli_connect')) {
    echo "mysqli is not available\n";
    exit(1);
}

$mysqli = @new mysqli('db', 'root', 'password', 'database', 3306);
if ($mysqli->connect_errno) {
    throw new Exception($mysqli->connect_error, $mysqli->connect_errno);
}

$db = new Database($mysqli);
$test = new DatabaseTest($db);
$test->testBuildQuery();
$test->testBuildQueryBenchmark();

exit('OK');
