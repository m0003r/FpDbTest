#!/bin/bash

cd /app

composer install

echo; echo; echo
echo -e "\e[1;33m **** RUNNING ORIGINAL TESTS **** \e[0m"
echo;

echo -n "DFA: ";
php src/test.php DFA
echo; echo;
echo -n "RexExp: ";
php src/test.php RegExp
echo; echo;

echo; echo; echo
echo -e "\e[1;33m **** RUNNING TESTS **** \e[0m"
echo
vendor/bin/phpunit --color

echo; echo; echo
echo -e "\e[1;33m **** RUNNING MUTATION TESTS **** \e[0m"
echo
vendor/bin/infection

echo; echo; echo
echo -e "\e[1;33m **** RUNNING BENCHMARKS (RegExp) **** \e[0m"
echo
vendor/bin/phpbench run --report=aggregate --tag=regexp

echo; echo; echo
echo -e "\e[1;33m **** RUNNING BENCHMARKS (DFA) **** \e[0m"
echo
DB_TYPE=DFA vendor/bin/phpbench run --report=aggregate --ref=regexp

echo; echo; echo
echo -e "\e[1;33m **** RUNNING BENCHMARKS (Rust DFA) **** \e[0m"
echo
DB_TYPE=RustDFA vendor/bin/phpbench run --report=aggregate --ref=regexp