Текст оригинального тестового задания: [README.txt](README.txt)

# Принятые допущения
- Никакого парсинга SQL не происходит, т.е плейсхолдеры внутри строк будут точно так же обработаны (и, возможно, вызовут ошибку). Полноценный парсер SQL — штука достаточно хитрая, и не такая быстрая, поэтому выглядит разумным конвенция «плейсхолдеры в любом месте будут обработаны», которая заставляет чуть больше думать, когда пишешь запросы, зато работать оно будет быстро  
- Экранирование работает через `mysqli::real_escape_string` (иначе зачем вообще там mysqli передаётся в запросопостроитель)
- `skip()` не разрешён как элемент массива (для `?a` и  `?#`), выбрасывается исключение
- `skip()` разрешён как единственное значение (`?#`) — кажется, никто в здравом уме так пользоваться этим не будет
- В остальном `?#` позволяет только строки
- Экранирование полей работает через `
- Касты в int и float не вызывают дополнительных проверок, т.е. туда можно передать всё что угодно 
- `skip()` между двумя экземплярами не взаимозаменяем. Если это существенно, можно сделать его `static` 

# Особенности реализации

В тесте лежат две разные реализации: более простая, с использованием `preg_split`, и более выпендрёжная, в которой на PHP реализован несложный конечный автомат. В основе они одинаковы: бьют строку на части по спец. символам, и собирают на выходе массив из готовых частей, которые объединяются с помощью `implode`.

При этом по результатам измерений оказалось, что реализация на регулярках работает _быстрее_, чем вручную выписанный (и, вроде бы, более оптимальный) конечный автомат. Вероятно, причина этого в том, что внутренняя работа со строками в PCRE быстрее, чем работа со строками в PHP.  Впрочем, это не точно. JIT не особо помогает, хотя разницу в производительности уменьшает.

**UPD:**
Добавил также реализацию в виде конечного автомата на Rust (заодно поиграл с ext-php-rs). Оказалось, что она быстрее, чем реализация на регулярках. Но в целом это борьба за доли микросекунды чисто для развлечения. Первая версия отставала от реализации на регулярках на строке без плейсхолдеров (т. к. там происходило лишнее копирование строки в Rust), но после оптимизации стала быстрее.

# Запуск тестов

Для удобства тестирования всё запаковано в docker-compose (поскольку для тестов требуется живая БД; выбрана MariaDB 11). Запускать тесты так:

```bash
docker compose build && docker compose run tests
# остановить БД
docker compose down
```

При этом запускаются:
- оригинальные тесты для двух реализаций
- значительно больше тестов на PHPUnit для обеих реализаций
- мутационное тестирование через Infection
- сравнение скорости через phpBench (jit включён)

В целом всё это работает достаточно быстро (порядка 1-2 µs на запрос), поэтому более простая для чтения реализация через регулярки предпочтительнее.

Результаты бенчамарка на моей машине (Ryzen 5 7530U):

```
 **** RUNNING BENCHMARKS (RegExp) **** 

+------------------+------------------------------+-----+--------+-----+----------+---------+--------+
| benchmark        | subject                      | set | revs   | its | mem_peak | mode    | rstdev |
+------------------+------------------------------+-----+--------+-----+----------+---------+--------+
| PerformanceBench | benchNoParams                |     | 100000 | 20  | 1.515mb  | 0.196μs | ±2.44% |
| PerformanceBench | benchWithParams              |     | 100000 | 20  | 1.515mb  | 0.611μs | ±2.26% |
| PerformanceBench | benchWithCondition           |     | 100000 | 20  | 1.515mb  | 0.852μs | ±1.67% |
| PerformanceBench | benchWithSkipCondition       |     | 100000 | 20  | 1.515mb  | 0.878μs | ±2.19% |
| PerformanceBench | benchWithManyParamsAndBlocks |     | 100000 | 20  | 1.515mb  | 4.702μs | ±3.17% |
+------------------+------------------------------+-----+--------+-----+----------+---------+--------+


 **** RUNNING BENCHMARKS (DFA) **** 

+------------------+------------------------------+-----+--------+-----+----------------+------------------+----------------+
| benchmark        | subject                      | set | revs   | its | mem_peak       | mode             | rstdev         |
+------------------+------------------------------+-----+--------+-----+----------------+------------------+----------------+
| PerformanceBench | benchNoParams                |     | 100000 | 20  | 1.515mb +0.00% | 0.686μs +250.12% | ±1.69% -30.61% |
| PerformanceBench | benchWithParams              |     | 100000 | 20  | 1.515mb +0.00% | 1.024μs +67.47%  | ±2.24% -0.86%  |
| PerformanceBench | benchWithCondition           |     | 100000 | 20  | 1.515mb +0.00% | 1.394μs +63.51%  | ±1.67% +0.24%  |
| PerformanceBench | benchWithSkipCondition       |     | 100000 | 20  | 1.515mb +0.00% | 1.437μs +63.60%  | ±2.53% +15.41% |
| PerformanceBench | benchWithManyParamsAndBlocks |     | 100000 | 20  | 1.515mb +0.00% | 6.469μs +37.60%  | ±1.93% -39.14% |
+------------------+------------------------------+-----+--------+-----+----------------+------------------+----------------+


 **** RUNNING BENCHMARKS (Rust DFA) **** 

+------------------+------------------------------+-----+--------+-----+----------------+-----------------+----------------+
| benchmark        | subject                      | set | revs   | its | mem_peak       | mode            | rstdev         |
+------------------+------------------------------+-----+--------+-----+----------------+-----------------+----------------+
| PerformanceBench | benchNoParams                |     | 100000 | 20  | 1.515mb +0.00% | 0.180μs -8.17%  | ±2.52% +3.44%  |
| PerformanceBench | benchWithParams              |     | 100000 | 20  | 1.515mb +0.00% | 0.521μs -14.71% | ±1.85% -18.12% |
| PerformanceBench | benchWithCondition           |     | 100000 | 20  | 1.515mb +0.00% | 0.620μs -27.31% | ±2.55% +52.93% |
| PerformanceBench | benchWithSkipCondition       |     | 100000 | 20  | 1.515mb +0.00% | 0.678μs -22.85% | ±2.34% +7.06%  |
| PerformanceBench | benchWithManyParamsAndBlocks |     | 100000 | 20  | 1.515mb +0.00% | 3.653μs -22.30% | ±1.82% -42.75% |
+------------------+------------------------------+-----+--------+-----+----------------+-----------------+----------------+
```