// Печать списка групп: столбцы и итоговая строка. Запуск: node backend/test-groups-print.cjs
//
// 28.08.2026 Жанна: «сделай возможность просто печатать список групп и разные
// колонки на выбор». Печать — бумага, которую понесут на совещание: столбец,
// съехавший относительно шапки, там уже не исправить. Поэтому проверяем ровно
// то, из-за чего это ломается, — что шапка, ячейки и итоги строятся из ОДНОГО
// списка столбцов и совпадают по числу и порядку.
//
// Проверяется НАСТОЯЩИЙ код из index.html: описание столбцов вырезается как есть.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const mCols = html.match(/\nconst GROUPS_PRINT_COLS\s*=\s*\[[\s\S]*?\n\];/m);
const mPre = html.match(/\nconst GROUPS_PRINT_PRESETS\s*=\s*\{[\s\S]*?\n\};/m);
if (!mCols || !mPre) { console.log('не найдено: GROUPS_PRINT_COLS / PRESETS'); process.exit(1); }
// esc() в столбцах — из страницы; для теста хватает подстановки такого же смысла
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const { GROUPS_PRINT_COLS, GROUPS_PRINT_PRESETS } =
  new Function('esc', mCols[0] + mPre[0] + '\nreturn {GROUPS_PRINT_COLS,GROUPS_PRINT_PRESETS};')(esc);

// Группа — как её собирает renderGroups().
const гр = (o = {}) => Object.assign({
  name: 'Roblox №2', course: 'Roblox', teacherText: 'Винтилов С.', schedText: 'Пн 16:00, Ср 16:00',
  roomText: 'Кластер', age: '11-13', klass: '', kids: 8, cap: 9, paid: 3,
  rYes: 5, rNo: 1, rMaybe: 1, rNone: 1, make: 'yes', done: false, newIntake: false,
  kidsAlfa: 4, pub: { lab: '✓ в Alfa' }
}, o);

console.log('Столбцы');
const ключи = GROUPS_PRINT_COLS.map(c => c.k);
check('ключи столбцов не повторяются', new Set(ключи).size === ключи.length, ключи.join(', '));
check('у каждого столбца есть подпись, шапка и значение',
  GROUPS_PRINT_COLS.every(c => c.lab && c.th && typeof c.get === 'function'));
const g = гр();
check('пустые поля печатаются прочерком, а не пустотой',
  GROUPS_PRINT_COLS.find(c => c.k === 'klass').get(g) === '—');
check('детей печатаются вместе с местами',
  GROUPS_PRINT_COLS.find(c => c.k === 'kids').get(g) === '8 / 9');
check('группа без мест печатается одним числом',
  GROUPS_PRINT_COLS.find(c => c.k === 'kids').get(гр({ cap: 0 })) === '8');
// «Делать?» на экране — выпадающий список, на бумаге должно быть слово
check('«делать?» печатается словом', GROUPS_PRINT_COLS.find(c => c.k === 'make').get(g) === 'да'
  && GROUPS_PRINT_COLS.find(c => c.k === 'make').get(гр({ make: 'no' })) === 'нет'
  && GROUPS_PRINT_COLS.find(c => c.k === 'make').get(гр({ make: '' })) === '—');
check('пометка нового набора попадает в название',
  /новый набор/.test(GROUPS_PRINT_COLS.find(c => c.k === 'name').get(гр({ newIntake: true }))));
// Пустая графа нужна ровно для того, чтобы в ней писали ручкой.
check('графа для отметок печатается пустой',
  GROUPS_PRINT_COLS.find(c => c.k === 'note').get(g) === '');
check('«в Alfa» без переноса детей — прочерк',
  GROUPS_PRINT_COLS.find(c => c.k === 'alfakids').get(гр({ kidsAlfa: 0 })) === '—');

console.log('\nНаборы столбцов');
Object.keys(GROUPS_PRINT_PRESETS).forEach(p => {
  const чужие = GROUPS_PRINT_PRESETS[p].filter(k => !ключи.includes(k));
  check('в наборе «' + p + '» нет выдуманных столбцов', !чужие.length, чужие.join(', '));
});
check('набор «всё» перечисляет все столбцы',
  GROUPS_PRINT_PRESETS.all.length === GROUPS_PRINT_COLS.length);
// Список без названия группы бесполезен на бумаге — в каждом наборе оно есть.
Object.keys(GROUPS_PRINT_PRESETS).forEach(p =>
  check('в наборе «' + p + '» есть название группы', GROUPS_PRINT_PRESETS[p].includes('name')));

console.log('\nШапка, строки и «Итого» одной ширины');
const выбор = GROUPS_PRINT_PRESETS.nabor;
const cols = GROUPS_PRINT_COLS.filter(c => выбор.includes(c.k));
const список = [гр(), гр({ name: 'Roblox №6', kids: 5, paid: 2, rYes: 2, rNo: 0, rMaybe: 1, rNone: 2, done: true })];
const шапка = cols.map(c => c.th);
const строки = список.map((x, i) => cols.map(c => c.get(x, i)));
check('в каждой строке столько же ячеек, сколько в шапке',
  строки.every(r => r.length === шапка.length), шапка.length + ' против ' + строки.map(r => r.length).join('/'));
// Итоги считаются по тем же ключам, что и столбцы: столбец, которого нет в
// таблице итогов, должен давать пустую ячейку, а не сдвигать строку.
const V = {
  name: 'ИТОГО: 2 гр.',
  kids: String(список.reduce((a, x) => a + x.kids, 0)),
  paid: String(список.reduce((a, x) => a + x.paid, 0)),
  ryes: String(список.reduce((a, x) => a + x.rYes, 0)),
  rno: String(список.reduce((a, x) => a + x.rNo, 0)),
  rmaybe: String(список.reduce((a, x) => a + x.rMaybe, 0)),
  rnone: String(список.reduce((a, x) => a + x.rNone, 0)),
  done: String(список.filter(x => x.done).length)
};
const итог = cols.map(c => V[c.k] || '');
check('строка «Итого» той же ширины', итог.length === шапка.length);
check('итог по детям сходится', итог[cols.findIndex(c => c.k === 'kids')] === '13');
check('итог по оплатам сходится', итог[cols.findIndex(c => c.k === 'paid')] === '5');
check('в графе «делать?» итога нет — там не число',
  итог[cols.findIndex(c => c.k === 'make')] === '');

console.log('\n' + (bad ? 'ПЛОХО: ' + bad : 'Всё сходится.'));
process.exit(bad ? 1 : 0);
