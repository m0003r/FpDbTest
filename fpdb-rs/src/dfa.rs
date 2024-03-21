use std::borrow::{Cow, ToOwned};
use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendObject, Zval};
use ext_php_rs::binary::Binary;
use ext_php_rs::flags::DataType;
use ext_php_rs::convert::IntoZval;

use memchr::memchr3_iter;

use lazy_static::lazy_static;

use crate::zend_casts;

lazy_static! {
    static ref NULL_STRING: Cow<'static, [u8]> = Cow::Owned(b"NULL".into());
    static ref ONE_STRING: Cow<'static, [u8]> = Cow::Owned(b"1".into());
    static ref ZERO_STRING: Cow<'static, [u8]> = Cow::Owned(b"0".into());
}


/// Быстрая проверка, что массив является списком
///
/// Списано с zend_array_is_list, которая не экспортируется, точнее,
/// с первой проверки внутри неё:
///
/// ```c
/// if (HT_IS_PACKED(array)) {
///   if (HT_IS_WITHOUT_HOLES(array)) {
///     return 1;
///   }
/// }
/// ```
fn array_is_list_fast(ht: &ZendHashTable) -> bool {
    // SAFETY: HT_IS_PACKED ровно так и определён
    let ht_is_packed = unsafe {
        ht.u.flags & (1 << 2) != 0
    };

    let ht_without_holes = ht.nNumOfElements == ht.nNumUsed;
    if ht_is_packed && ht_without_holes {
        return true;
    }

    // иначе проверяем просто все ключи подряд
    ht.has_sequential_keys()
}

pub struct QueryParser {
    pub skip: ZBox<ZendObject>,
    pub myqsli: Zval,
}

impl QueryParser {
    pub fn __construct(mysqli: &Zval) -> Result<QueryParser, String> {
        if mysqli.object().and_then(|o| o.get_class_name().ok()) != Some("mysqli".to_string()) {
            return Err("Expected mysqli object".to_string());
        }

        Ok(QueryParser {
            skip: ZendObject::new_stdclass(),
            myqsli: mysqli.shallow_clone(),
        })
    }

    /// Возвращает объект, который будет использоваться для пропуска значений
    /// Эквивалентно return $this->skip в PHP
    pub fn skip(&mut self) -> Zval {
        let mut zval = Zval::new();
        zval.set_object(&mut self.skip);
        zval
    }

    pub fn build_query(&self, query_zval: &Zval, args: &Zval) -> Result<Zval, String> {
        let query = query_zval.zend_str().ok_or("Expected string")?.as_bytes();

        let args = args.array().ok_or("Expected list")?;
        if !array_is_list_fast(args) {
            return Err("Expected list".to_string());
        }
        let mut args_iter = args.iter();
        let mut output: Vec<u8> = Vec::with_capacity(query.len() + args_iter.len() * 2);

        let mut conditional_output: Vec<u8> = Vec::with_capacity(32); // буфер для условий
        let mut inside_conditional = false; // находимся ли мы внутри { ... }
        let mut render_conditional = false; // рендерить ли текущее

        let mut current_output = &mut output; // текущий буфер (основной или условный)
        let mut r = 0; // индекс, с которого начинается очередной фрагмент без спец.символов

        // используем быстрый поиск сразу трёх символов
        for i in memchr3_iter(b'?', b'{', b'}', query) {
            let c = query[i];

            // если спец.символы идут не подряд, то копируем всё, что между ними
            if i > r {
                current_output.extend_from_slice(&query[r..i]);
            }

            if c == b'{' {
                if inside_conditional {
                    return Err(format!("Nested {{ at {}", i));
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
                    return Err(format!("Unmatched }} at {}", i));
                }

                inside_conditional = false;
                current_output = &mut output;

                if render_conditional {
                    current_output.extend_from_slice(&conditional_output);
                }

                r = i + 1;
                continue;
            }

            let (k, arg) = args_iter.next().ok_or(format!("Too few arguments at {}", i))?;

            if let Some(obj) = arg.object() {
                if obj.handle == self.skip.handle && obj.handlers == self.skip.handlers {
                    if !inside_conditional {
                        return Err(format!("Skip value (argument #{}) outside of conditional at {}", k, i));
                    }

                    render_conditional = false;
                    continue;
                }
            }

            if inside_conditional && !render_conditional {
                continue;
            }

            let arg_spec = query.get(i + 1);


            r = i + 2;
            let format_result = match arg_spec {
                Some(b'd') => self.format_integer(arg),
                Some(b'f') => self.format_float(arg),
                Some(b'a') => self.format_ht(arg),
                Some(b'#') => self.format_fields(arg),
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

        // do not copy string if there is no arguments
        if r == 0 {
            return Ok(query_zval.shallow_clone());
        }

        if r < query.len() {
            current_output.extend_from_slice(&query[r..]);
        }

        Binary::from(output).into_zval(false).map_err(|_| "Failed to convert string to zval".to_string())
    }

    fn format_integer<'a>(&'_ self, arg: &'a Zval) -> Result<Cow<'a, [u8]>, String> {
        if arg.get_type() == DataType::Null {
            return Ok(NULL_STRING.clone());
        }

        zend_casts::as_long_as_string(arg);

        Ok(arg
            .zend_str()
            .ok_or("Expected string")?
            .as_bytes()
            .as_ref()
            .into())
    }

    fn format_float<'a>(&'_ self, arg: &'a Zval) -> Result<Cow<'a, [u8]>, String> {
        if arg.get_type() == DataType::Null {
            return Ok(NULL_STRING.clone());
        }

        zend_casts::as_float_as_string(arg);

        Ok(arg
            .zend_str()
            .ok_or("Expected string")?
            .as_bytes()
            .as_ref()
            .into())
    }

    fn format_ht(&self, arg: &Zval) -> Result<Cow<[u8]>, String> {
        let ht = arg.array().ok_or("Expected array")?;
        if array_is_list_fast(ht) {
            self.format_list(ht)
        } else {
            self.format_assoc(ht)
        }
    }

    fn format_list(&self, ht: &ZendHashTable) -> Result<Cow<[u8]>, String> {
        let mut output = Vec::with_capacity(ht.len() * 3);
        let mut first = true;
        for (_, value) in ht.iter() {
            if first {
                first = false;
            } else {
                output.extend_from_slice(b", ");
            }
            if let Some(a) = value.array() {
                if array_is_list_fast(a) {
                    let value = self.format_list(a)?;
                    output.extend_from_slice(&value);
                    continue;
                }
            }
            let value = self.format_unspecified(value)?;
            output.extend_from_slice(&value);
        }
        Ok(output.into())
    }

    fn format_assoc(&self, ht: &ZendHashTable) -> Result<Cow<[u8]>, String> {
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

            output.extend_from_slice(key.as_bytes());
            output.extend_from_slice(b"` = ");

            let value = self.format_unspecified(value)?;
            output.extend_from_slice(&value);
        }
        Ok(output.into())
    }

    fn format_fields(&self, arg: &Zval) -> Result<Cow<[u8]>, String> {
        // check if it is string
        if let Some(s) = arg.zend_str() {
            let s = s.as_bytes();
            let mut output = Vec::with_capacity(s.len() + 2);
            output.extend_from_slice(b"`");
            output.extend_from_slice(s);
            output.extend_from_slice(b"`");
            return Ok(output.into())
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
            return Ok(output.into())
        }

        Err("Argument for ?# should be string or array of strings".to_string())
    }

    fn format_unspecified<'a>(&'_ self, arg: &'a Zval) -> Result<Cow<'a, [u8]>, String> {
        match arg.get_type() {
            DataType::String => {
                let escaped = self.escape_string(arg)?;
                let mut res: Vec<u8> = Vec::with_capacity(escaped.len() + 2);
                res.extend_from_slice(b"'");
                res.extend_from_slice(&escaped);
                res.extend_from_slice(b"'");
                Ok(res.into())
            }
            DataType::Null => Ok(NULL_STRING.clone()),
            DataType::Long => self.format_integer(arg),
            DataType::Double => self.format_float(arg),
            DataType::Bool => {
                if arg.bool().unwrap() {
                    Ok(ONE_STRING.clone())
                } else {
                    Ok(ZERO_STRING.clone())
                }
            },
            DataType::True => Ok(ONE_STRING.clone()),
            DataType::False => Ok(ZERO_STRING.clone()),
            _ => Err("Unsupported type".to_string()),
        }
    }

    fn escape_string(&self, string: &Zval) -> Result<Binary<u8>, String> {
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
