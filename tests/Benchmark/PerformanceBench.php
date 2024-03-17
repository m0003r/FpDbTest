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
#[Bench\Revs(100000)]
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

    public function benchNoParams()
    {
        $this->db->buildQuery('SELECT * FROM table WHERE id = 1 AND name = \'test\'', []);
    }

    public function benchWithParams()
    {
        $this->db->buildQuery('SELECT * FROM table WHERE id = ?d AND name = ?', [1, 'test']);
    }

    public function benchWithCondition()
    {
        $this->db->buildQuery('SELECT * FROM table WHERE id = ?d AND name = ?{ AND block = ?d}', [1, 'test', 1]);
    }

    public function benchWithSkipCondition()
    {
        $this->db->buildQuery('SELECT * FROM table WHERE id = ?d AND name = ?{ AND block = ?d}', [1, 'test', $this->db->skip()]);
    }

    public function benchWithManyParamsAndBlocks()
    {
        $this->db->buildQuery('SELECT * FROM table WHERE id = ?d AND name = ?{ AND block = ?d} AND age = ?d 
AND age2 = ?d AND age3 = ?d{ AND age4 = ?d }AND age5 = ?d AND age6 = ?d 
AND age7 = ?d{ AND age8 = ?d AND age9 = ?d }AND age10 = ?d AND test_value IN (?a)',
            [1, 'test',
                1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 1, ['test', 'THIS IS REALLY LONG STRING',
                'LONG LONG long long LONG long LONG LONG long long LONG long LONG LONG long long LONG long 
                LONG LONG long long LONG long LONG LONG long long LONG long LONG LONG LONG long long LONG long 
                LONG LONG long long LONG long LONG LONG long long LONG long LONG LONG long long LONG long ']]);
    }
}