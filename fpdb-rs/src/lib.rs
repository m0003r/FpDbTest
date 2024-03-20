mod dfa;
mod zend_casts;

use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;

use crate::dfa::QueryParser;

// Здесь используется паттерн newtype, чтобы скрыть от экспорта в PHP
// методы QueryParser, которые не должны быть доступны извне
#[php_class]
struct RustDFA(QueryParser);

#[php_impl]
impl RustDFA {
    #[php_method]
    pub fn __construct(mysqli: &Zval) -> Result<Self, String> {
        QueryParser::__construct(mysqli).map(RustDFA)
    }

    #[php_method]
    pub fn skip(&mut self) -> Zval {
        self.0.skip()
    }

    pub fn build_query(&self, query_zval: &Zval, args: &Zval) -> Result<Zval, String> {
        self.0.build_query(query_zval, args)
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}