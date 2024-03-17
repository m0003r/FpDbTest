<?php

namespace Benchmark;

use FpDbTest\DatabaseInterface;
use FpDbTest\DFADatabase;
use FpDbTest\RegExpDatabase;
use FpDbTest\RustDFADatabase;
use FpDbTest\Tests\MysqliFactory;
use PhpBench\Attributes as Bench;

use FpDbTest\Tests\AbstractDatabaseTestCase;

#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(100)]
#[Bench\Iterations(20)]
#[Bench\RetryThreshold(10)]
#[Bench\Warmup(10)]
class PerformanceBench
{
    private DatabaseInterface $db;

    public function setUp()
    {
        $mysqli = MysqliFactory::createMysqli();
        $this->db = match (getenv('DB_TYPE')) {
            'DFA' => new DFADatabase($mysqli),
            'RustDFA' => new RustDFADatabase($mysqli),
            default => new RegExpDatabase($mysqli),
        };;
    }

    public function benchNoParams() {
        $this->db->buildQuery('SELECT * FROM table WHERE id = 1 AND name = \'test\'', []);
    }

    public function benchWithParams() {
        $this->db->buildQuery('SELECT * FROM table WHERE id = ?d AND name = ?', [1, 'test']);
    }

    public function benchWithCondition() {
        $this->db->buildQuery('SELECT * FROM table WHERE id = ?d AND name = ?{ AND block = ?d}', [1, 'test', 1]);
    }

    public function benchWithSkipCondition() {
        $this->db->buildQuery('SELECT * FROM table WHERE id = ?d AND name = ?{ AND block = ?d}', [1, 'test', $this->db->skip()]);
    }
}