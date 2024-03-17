<?php

namespace FpDbTest;

use mysqli;

class RustDFADatabase implements DatabaseInterface
{
    private \RustDFA $internal;

    public function __construct(private readonly mysqli $mysqli)
    {
        $this->internal = new \RustDFA($mysqli);
    }

    public function buildQuery(string $query, array $args = []): string
    {
        return $this->internal->buildQuery($query, $args);
    }

    public function skip()
    {
        return $this->internal->skip();
    }
}
