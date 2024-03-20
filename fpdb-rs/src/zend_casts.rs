use ext_php_rs::types::Zval;

extern "C" {
    fn convert_to_long(ht: *mut Zval);
    fn convert_to_double(ht: *mut Zval);
    fn _convert_to_string(ht: *mut Zval);
}

pub fn as_long_as_string(v: &Zval) {
    let zval_ptr = v as *const Zval as *mut Zval;
    // SAFETY: `v` is a valid pointer to a Zval, zend casts are safe
    unsafe {
        convert_to_long(zval_ptr);
        _convert_to_string(zval_ptr);
    }
}

pub fn as_float_as_string(v: &Zval) {
    let zval_ptr = v as *const Zval as *mut Zval;
    // SAFETY: `v` is a valid pointer to a Zval, zend casts are safe
    unsafe {
        convert_to_double(zval_ptr);
        _convert_to_string(zval_ptr);
    }
}
