// Прогон ВСЕХ тестов проекта одной командой.
// Запуск: node backend/test-all.cjs
//
// Зачем отдельный файл. Тесты вырезают настоящие функции из index.html регулярками. Стоит
// переименовать функцию, разделить её на две или сделать однострочную многострочной — и тест
// падает НЕ проверкой, а ReferenceError ещё до первой строчки. Такое падение легко принять за
// зелёный прогон: вывод почти пустой, слова «ПЛОХО» в нём нет.
// Так и вышло: 09.09.2026 разом молчали три набора — test-fill.cjs (удалили _fillMonthMissing),
// test-salesreport.cjs (_salesFillLines разделили на _salesFillLinesFor) и test-expect-column.cjs
// (у «Реализации» появилось окно «от даты до даты» с новыми _realFrom/_realTo). Вместе это
// больше 380 проверок, которые неделю никого не охраняли.
//
// Поэтому здесь провал считается по ДВУМ признакам сразу:
//   • код возврата ≠ 0 — сюда попадают и необработанные исключения, и process.exit(1);
//   • слова-маркеры в выводе — на случай теста, который сам о себе не сообщает кодом.
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const dir = __dirname;
const MARK = /ПРОВАЛЕНО|ПЛОХО|не найдено в index\.html|is not defined|Fatal error|PHP (Warning|Fatal)/i;
/* Сколько проверок прошло. Наборы пишут по-разному: «  ok   …», «ok   …», «✓ …». Считаем все
   три вида — иначе живой тест выглядел бы пустым, а пустой не отличался бы от живого. */
const OK = /^(\s*ok\s|\s*✓\s)/;

const files = fs.readdirSync(dir)
  .filter(n => /^test-.+\.(cjs|php)$/.test(n) && n !== 'test-all.cjs')
  .sort();

const runners = { '.cjs': process.execPath, '.php': 'php' };
let failed = [], total = 0, skipped = [];

for (const name of files) {
  const ext = path.extname(name);
  const r = spawnSync(runners[ext], [path.join(dir, name)], { encoding: 'utf8', cwd: path.join(dir, '..') });
  /* php может быть не установлен — это не провал теста, но и молчать нельзя: серверная часть
     тогда просто не проверена, и знать об этом важнее, чем видеть зелёный итог. */
  if (r.error && r.error.code === 'ENOENT') { skipped.push(name + ' (нет ' + runners[ext] + ')'); continue; }
  const out = (r.stdout || '') + (r.stderr || '');
  const checks = out.split(/\r?\n/).filter(l => OK.test(l)).length;
  total += checks;
  const bad = r.status !== 0 || MARK.test(out);
  console.log((bad ? '❌ ' : '✅ ') + name.padEnd(30) + String(checks).padStart(4) + ' проверок' +
              (bad ? '   код возврата ' + r.status : ''));
  if (bad) {
    failed.push(name);
    out.split(/\r?\n/).filter(l => MARK.test(l) || /Error/.test(l)).slice(0, 6)
       .forEach(l => console.log('       ' + l.trim()));
  }
}

if (skipped.length) console.log('\n⚠️  пропущено: ' + skipped.join(', '));
console.log('\nНаборов: ' + (files.length - skipped.length) + ', проверок: ' + total);
if (failed.length) { console.log('ПРОВАЛЕНО: ' + failed.join(', ')); process.exit(1); }
console.log('Всё сошлось');
