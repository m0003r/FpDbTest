use ext_php_rs::convert::IntoZvalDyn;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendHashTable};
use ext_php_rs::{
    binary::Binary,
    binary_slice::BinarySlice,
    boxed::ZBox,
    flags::DataType,
    types::{ZendClassObject, ZendObject, Zval},
};
use memchr::memchr3_iter;

extern "C" {
    pub fn convert_to_long(ht: *mut Zval);
    pub fn convert_to_double(ht: *mut Zval);
    pub fn _convert_to_string(ht: *mut Zval);
}

#[php_function]
fn array_is_list_fast(ht: &ZendHashTable) -> bool {
    if (unsafe { ht.u.flags & (1 << 2) != 0 }) && (ht.nNumOfElements == ht.nNumUsed) {
        return true;
    }
    return ht.has_sequential_keys();
}

#[php_class]
#[derive(Debug)]
pub struct RustDFA {
    pub skip: ZBox<ZendObject>,
    pub myqsli: Zval,
}

#[php_impl]
impl RustDFA {
    pub fn __construct(mysqli: &mut Zval) -> Result<RustDFA, String> {
        if mysqli.object().and_then(|o| o.get_class_name().ok()) != Some("mysqli".to_string()) {
            return Err("Expected mysqli object".to_string());
        }

        let mut zval = Zval::new();
        zval.set_object(mysqli.object_mut().unwrap());


        Ok(RustDFA {
            skip: ZendObject::new_stdclass(),
            myqsli: zval,
        })
    }

    #[php_method]
    pub fn skip(&mut self) -> Zval {
        let mut zval = Zval::new();
        zval.set_object(&mut self.skip);
        zval
    }

    #[php_method]
    pub fn build_query(&self, query: BinarySlice<u8>, args: &Zval) -> Result<Binary<u8>, String> {
        let args = args.array().ok_or("Expected liat")?;
        if !array_is_list_fast(&args) {
            return Err("Expected list".to_string());
        }
        let mut args_iter = args.iter();
        let mut output: Vec<u8> = Vec::with_capacity(query.len() + args_iter.len() * 2);

        let mut conditional_output: Vec<u8> = Vec::with_capacity(32);
        let mut inside_conditional = false;
        let mut render_conditional = false;

        let mut current_output = &mut output;
        let mut r = 0;

        for i in memchr3_iter(b'?', b'{', b'}', query.as_ref()) {
            let c = query[i];

            if i > r {
                current_output.extend_from_slice(&query[r..i]);
            }

            if c == b'{' {
                if inside_conditional {
                    return Err("Nested conditionals are not supported".to_string());
                }
                inside_conditional = true;
                render_conditional = true;
                conditional_output.clear();
                current_output = &mut conditional_output;
                r = i + 1;
                continue;
            }

            if c == b'}' {
                if !inside_conditional {
                    return Err("Unmatched }".to_string());
                }

                inside_conditional = false;
                current_output = &mut output;

                if render_conditional {
                    current_output.extend_from_slice(&conditional_output);
                }

                r = i + 1;
                continue;
            }

            let (_, arg) = args_iter.next().ok_or("Too few arguments")?;

            if let Some(obj) = arg.object() {
                if obj.handle == self.skip.handle && obj.handlers == self.skip.handlers {
                    if !inside_conditional {
                        return Err("Skip value outside of conditional".to_string());
                    }

                    render_conditional = false;
                    continue;
                }
            }

            if inside_conditional && !render_conditional {
                continue;
            }

            let arg_spec = query.get(i + 1);


            let format_result = match arg_spec {
                Some(b'd') => {
                    r = i + 2;
                    self.format_integer(arg)
                }
                Some(b'f') => {
                    r = i + 2;
                    self.format_float(arg)
                }
                Some(b'a') => {
                    r = i + 2;
                    self.format_ht(arg)
                }
                Some(b'#') => {
                    r = i + 2;
                    self.format_fields(arg)
                }
                _ => {
                    r = i + 1;
                    self.format_unspecified(arg)
                }
            }?;

            current_output.extend_from_slice(&format_result);
        }

        if inside_conditional {
            return Err("Unmatched {".to_string());
        }

        if args_iter.next().is_some() {
            return Err("Too many arguments".to_string());
        }

        if r < query.len() {
            current_output.extend_from_slice(&query[r..]);
        }

        Ok(Binary::from(output))
    }

    fn format_integer(&self, arg: &Zval) -> Result<Vec<u8>, String> {
        if arg.get_type() == DataType::Null {
            return Ok(b"NULL".to_vec());
        }

        unsafe {
            convert_to_long(arg as *const Zval as *mut Zval);
            _convert_to_string(arg as *const Zval as *mut Zval);
        }
        Ok(arg
            .zend_str()
            .ok_or("Expected string")?
            .as_bytes()
            .to_owned())
    }

    fn format_float(&self, arg: &Zval) -> Result<Vec<u8>, String> {
        if arg.get_type() == DataType::Null {
            return Ok(b"NULL".to_vec());
        }

        unsafe {
            convert_to_double(arg as *const Zval as *mut Zval);
            _convert_to_string(arg as *const Zval as *mut Zval);
        }
        Ok(arg
            .zend_str()
            .ok_or("Expected string")?
            .as_bytes()
            .to_owned())
    }

    fn format_ht(&self, arg: &Zval) -> Result<Vec<u8>, String> {
        let ht = arg.array().ok_or("Expected array")?;
        if array_is_list_fast(&ht) {
            self.format_list(ht)
        } else {
            self.format_assoc(ht)
        }
    }

    fn format_list(&self, ht: &ZendHashTable) -> Result<Vec<u8>, String> {
        let mut output = Vec::with_capacity(ht.len() * 3);
        let mut first = true;
        for (_, value) in ht.iter() {
            if first {
                first = false;
            } else {
                output.extend_from_slice(b", ");
            }
            if let Some(a) = value.array() {
                if array_is_list_fast(&a) {
                    let value = self.format_list(a)?;
                    output.extend_from_slice(&value);
                    continue;
                }
            }
            let value = self.format_unspecified(value)?;
            output.extend_from_slice(&value);
        }
        Ok(output)
    }

    fn format_assoc(&self, ht: &ZendHashTable) -> Result<Vec<u8>, String> {
        let mut output = Vec::with_capacity(ht.len() * 8);
        output.extend_from_slice(b"`");
        let mut first = true;
        for (key, value) in ht.iter() {
            if first {
                first = false;
            } else {
                output.extend_from_slice(b", `");
            }

            let key = match key {
                ArrayKey::String(key) => key,
                ArrayKey::Long(_) => return Err("Expected string keys".to_string())
            };

            output.extend_from_slice(&key.as_bytes());
            output.extend_from_slice(b"` = ");

            let value = self.format_unspecified(value)?;
            output.extend_from_slice(&value);
        }
        Ok(output)
    }

    fn format_fields(&self, arg: &Zval) -> Result<Vec<u8>, String> {
        // check if it is string
        if let Some(s) = arg.zend_str() {
            let s = s.as_bytes();
            let mut output = Vec::with_capacity(s.len() + 2);
            output.extend_from_slice(b"`");
            output.extend_from_slice(s);
            output.extend_from_slice(b"`");
            return Ok(output)
        }

        // check if it is an array
        if let Some(ht) = arg.array() {
            let mut output = Vec::with_capacity(ht.len() * 8);
            output.extend_from_slice(b"`");
            let mut first = true;
            for (_, value) in ht.iter() {
                if first {
                    first = false;
                } else {
                    output.extend_from_slice(b"`, `");
                }
                if let Some(s) = value.zend_str() {
                    output.extend_from_slice(s.as_bytes());
                } else {
                    return Err("Argument for ?# should be string or array of strings".to_string());
                }
            }
            output.extend_from_slice(b"`");
            return Ok(output)
        }

        return Err("Argument for ?# should be string or array of strings".to_string());
    }

    fn format_unspecified(&self, arg: &Zval) -> Result<Vec<u8>, String> {
        match arg.get_type() {
            DataType::String => {
                let escaped = self.escape_string(arg)?;
                let mut res: Vec<u8> = Vec::with_capacity(escaped.len() + 2);
                res.extend_from_slice(b"'");
                res.extend_from_slice(&escaped);
                res.extend_from_slice(b"'");
                Ok(res)
            }
            DataType::Null => Ok(b"NULL".to_vec()),
            DataType::Long => self.format_integer(arg),
            DataType::Double => self.format_float(arg),
            DataType::Bool => {
                if arg.bool().unwrap() {
                    Ok(b"1".to_vec())
                } else {
                    Ok(b"0".to_vec())
                }
            },
            DataType::True => Ok(b"1".to_vec()),
            DataType::False => Ok(b"0".to_vec()),
            _ => Err("Unsupported type".to_string()),
        }
    }

    #[php_method]
    pub fn debug(#[this] this: &mut ZendClassObject<RustDFA>) {
        // create new stdclass
        let stdclass = ZendObject::new_stdclass();
        let skip = this.skip();
        dbg!(&stdclass.hash());
        dbg!(&skip.object().unwrap().hash());
    }

    #[php_method]
    pub fn escape_string(&self, string: &Zval) -> Result<Binary<u8>, String> {
        self.myqsli
            .try_call_method("real_escape_string", vec![string])
            .map_err(|_| "Failed to call real_escape_string".to_string())
            .and_then(|z| {
                Ok(z.zend_str()
                    .ok_or("Expected string result from real_escape_string".to_string())?
                    .as_bytes()
                    .to_owned()
                    .into())
            })
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
