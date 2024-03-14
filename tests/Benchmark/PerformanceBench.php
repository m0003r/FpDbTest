<?php

namespace Benchmark;

use FpDbTest\DFADatabase;
use FpDbTest\RegExpDatabase;
use FpDbTest\Tests\MysqliFactory;
use PhpBench\Attributes as Bench;

use FpDbTest\Tests\AbstractDatabaseTestCase;

#[Bench\BeforeMethods('setUp')]
class PerformanceBench
{
    private DFADatabase $dfa;
    private RegExpDatabase $regExp;

    public function setUp()
    {
        $mysqli = MysqliFactory::createMysqli();
        $this->dfa = new DFADatabase($mysqli);
        $this->regExp = new RegExpDatabase($mysqli);
    }

    public function getQuerySamples()
    {
        yield from AbstractDatabaseTestCase::originalTests();
    }


    #[Bench\ParamProviders(['getQuerySamples'])]
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    public function benchRegExpDatabase(array $params)
    {
        $this->regExp->buildQuery($params[0], $params[1]);
    }

    #[Bench\ParamProviders(['getQuerySamples'])]
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    public function benchDFADatabase(array $params)
    {
        $this->dfa->buildQuery($params[0], $params[1]);
    }
}