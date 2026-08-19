// Сопоставление ФИО с Альфой: порядок слов не должен мешать, тёзки — должны.
// Запуск: node backend/test-fio-match.cjs
//
// 19.08.2026 Жанна: «почему финмодель не видит этого ребёнка в Альфе». Павел
// Колычев (id 3805) в CRM есть, а в наборе значился «не найден в Альфе».
// Причина: в Альфе карточка заведена из amoCRM как «Павел Колычев» — имя
// вперёд, — а у нас в списках «Колычев Павел». Ключ строился из двух первых
// слов «как есть», поэтому написания не совпадали.
//
// Здесь проверяется НАСТОЯЩИЙ код из index.html: функции вырезаются из файла
// и выполняются как есть.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
function grab(name, kind) {
  const re = new RegExp('\\n(?:const |let )?' + name + (kind === 'const' ? '\\s*=\\s*[^;]+;' : '\\s*\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}'), 'm');
  const m = html.match(kind === 'const' ? re : new RegExp('\\nfunction ' + name + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  if (!m) { console.log('не найдено: ' + name); process.exit(1); }
  return m[0];
}

const parts = [
  grab('_CONFUSE', 'const'),
  grab('cleanKidName'),
  grab('_foldLat'),
  grab('_normName'),
  grab('_nameWords'),
  grab('_sameKid'),
  grab('_nameKey'),
];
const src = parts.join('\n');
const F = new Function(`
  const _nwCache = new Map();
  ${src}
  return { _normName, _sameKid, _nameKey, _nameWords };
`)();

console.log('Один ребёнок, записанный по-разному:');
check('«Колычев Павел» = «Павел Колычев»', F._sameKid('Колычев Павел', 'Павел Колычев'));
check('ключ у обоих написаний одинаковый',
  F._normName('Колычев Павел') === F._normName('Павел Колычев'),
  F._normName('Колычев Павел') + ' / ' + F._normName('Павел Колычев'));
check('с отчеством тоже находится',
  F._sameKid('Колычев Павел', 'Павел Колычев Игоревич'));
check('лишние пробелы и «ё» не мешают',
  F._sameKid('Алёшин  Пётр ', 'Петр Алешин'));

console.log('\nРазных детей по-прежнему не путаем:');
check('однофамильцы с разными отчествами — разные',
  !F._sameKid('Полонников Даниил Ильич', 'Полонников Даниил Петрович'));
check('разные дети — разные', !F._sameKid('Иванов Пётр', 'Петров Иван'));
check('однословные — только точное совпадение',
  F._sameKid('Алиса', 'Алиса') && !F._sameKid('Алиса', 'Алисова'));
check('одно слово против двух — не совпадение', !F._sameKid('Алиса', 'Алиса Петрова'));
check('пустые имена не считаются одинаковыми', !F._sameKid('', ''));

console.log('\nКлюч группировки:');
check('ключ не зависит от порядка слов',
  F._normName('Соловьев Степан') === F._normName('Степан Соловьев'));
check('ключ отличает разных детей',
  F._normName('Иванов Пётр') !== F._normName('Петров Иван'));
check('отчество на ключ не влияет',
  F._normName('Колычев Павел Игоревич') === F._normName('Колычев Павел'));
check('полный ключ дедупа сохраняет все слова',
  F._nameKey('Колычев Павел Игоревич').split(' ').length === 3);

console.log(bad ? '\nПЛОХО: ' + bad + '\n' : '\nВсё хорошо.\n');
process.exit(bad ? 1 : 0);
