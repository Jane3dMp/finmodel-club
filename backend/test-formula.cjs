// «Формула 26/27» (юнит-экономика): разбор занятия, три единицы, порог нуля.
// Запуск: node backend/test-formula.cjs
//
// Проверяется НАСТОЯЩИЙ код из index.html — функции вырезаются из файла и исполняются.
// Расходы приходят из _expenseData (та же таблица «Расходы по статьям»), здесь он подменён
// контролируемым набором: проверяем не расходы, а как из них получаются единицы.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want, eps) {
  const ok = (typeof want === 'number') ? Math.abs(got - want) <= (eps == null ? 0.01 : eps) : got === want;
  check(name, ok, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want));
}

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const NAMES = ['_fmlRates', '_fmlData', '_fmlCourses', '_fmlLesson', '_fmlMinFill', '_formulaHtml', '_fmlKid',
               '_rashodKindDefault', '_rashodFact', '_rashodFactAvg'];
const ONE_LINERS = ['rashodKind'];
const CONSTS = ['RASHOD_VAR_HINTS'];
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of CONSTS)     grab(n, new RegExp('\\nconst ' + n + '=.*\\n', 'm'));
for (const n of NAMES)      grab(n, new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
for (const n of ONE_LINERS) grab(n, new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));

/* --- расходы за 2 учебных месяца: выручка 20 000, переменные 8 000, постоянные 9 000 --- */
const courses = () => ({
  'Скретч':  { rev: 6000, wage: 1600, lessons: 30, groups: 5, kids: 40 },
  'Роблокс': { rev: 4000, wage: 1200, lessons: 20, groups: 3, kids: 21 },
});
const MONTHS = [
  { ym: '2026-09', kidsU: 60, lessons: 50, courses: courses() },
  { ym: '2026-10', kidsU: 70, lessons: 50, courses: courses() },
];
const E = {
  ym: '', mN: 2, revY: 20000, zavY: 8000, fixY: 9000, totalY: 17000, profitY: 3000,
  lessonsY: 100, seatsY: 500, months: MONTHS, all: MONTHS, mo: n => n / 2,
};

/* --- ставки: УСН 6%, эквайринг 1%, бытовые 2%, взносы 34,6%; ставка курса 20 + 3 за ребёнка сверх 4 --- */
const ctx = {
  S: { tax: { usn: 6, acquiring: 1, fszn: 34, belgosstrah: 0.6 }, fixedPct: [{ name: 'Бытовые', pct: 2 }] },
  _expenseData: () => E,
  _planPrice: c => ({ p: c === 'Скретч' ? 25 : 30, m: 20, known: true }),
  wageForLesson: (c, n) => 20 + 3 * (Math.max(4, Math.min(12, n || 4)) - 4),
  _rukSec: 'formula',
  _fmlMonth: '', _fmlCourse: null, _fmlN: null,
  fmt: n => (n == null || isNaN(n)) ? '—' : Math.round(n).toLocaleString('ru-RU'),
  fmt1: n => (Math.round(n * 10) / 10).toLocaleString('ru-RU'),
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  _expMonLabel: ym => ({ '2026-09': 'сен', '2026-10': 'окт' })[ym] || ym,
  _planLabel: ym => ym,
  localStorage: { getItem: () => '', setItem: () => {} },
  document: { getElementById: () => null },
  // расходы: снимок Alfa по дням и ручной ввод по месяцам (однострочные в index.html)
  _paySnap: null,
  _rashodManual: () => ctx.S.rashodManual || (ctx.S.rashodManual = {}),
  _gm: n => Math.round(+n || 0).toLocaleString('ru-RU'),
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_fmlRates,_fmlData,_fmlCourses,_fmlLesson,_fmlMinFill,_formulaHtml,_fmlKid,' +
  '_rashodKindDefault,rashodKind,_rashodFact,_rashodFactAvg}; }')(ctx);

/* ================= ставки ================= */
const R = API._fmlRates();
eq('процент с оборота: УСН', R.usn, 6);
eq('процент с ЗП: ФСЗН + Белгосстрах', R.con, 34.6);
eq('расходы % от оборота собираются со всех строк', R.pct, 2);

/* ================= три единицы ================= */
const D = API._fmlData('');
eq('месяцев в периоде', D.mN, 2);
eq('занятий за период', D.lessons, 100);
eq('занятий в месяц', D.lessonsM, 50);
eq('детей в среднем за месяц', D.kids, 65);          // (60+70)/2
eq('групп в среднем за месяц', D.groups, 8);         // (5+3)+(5+3) на 2 месяца

eq('месяц: выручка', D.month.rev, 10000);
eq('месяц: переменные', D.month.vr, 4000);
eq('месяц: постоянные', D.month.fix, 4500);
eq('месяц: прибыль', D.month.profit, 1500);

eq('занятие: выручка', D.lesson.rev, 200);
eq('занятие: вклад', D.lesson.contrib, 120);
eq('занятие: прибыль', D.lesson.profit, 30);
// главное обещание раздела: единицы не живут своей жизнью
eq('прибыль всех занятий = прибыль периода', D.lesson.profit * D.lessons, E.profitY);
eq('прибыль всех детей = прибыль месяца', D.kid.profit * D.kids, D.month.profit);
eq('прибыль всех групп = прибыль месяца', D.group.profit * D.groups, D.month.profit);

/* ================= порог нуля ================= */
eq('вклад одного ребёнка в месяц', D.kid.contrib, 6000 / 65);
eq('детей до нуля', Math.ceil(D.kidsBE), 49);        // 4500 / (6000/65) = 48,75
eq('занятий до нуля', Math.ceil(D.lessonsBE), 38);   // 4500 / 120 = 37,5
check('порог достигнут: детей сейчас больше, чем нужно', D.kids > D.kidsBE,
      D.kids + ' vs ' + D.kidsBE);

/* ================= разбор одного занятия ================= */
const L = API._fmlLesson('Скретч', 8, 60);
eq('выручка = цена × дети', L.rev, 200);
eq('ЗП по ставке при 8 детях', L.wage, 32);
eq('УСН 6% с выручки', L.usn, 12);
eq('эквайринг 1%', L.acq, 2);
eq('бытовые 2%', L.pct, 4);
eq('взносы 34,6% с ЗП педагога', L.con, 11.072);
eq('переменные — сумма всех пяти строк', L.vr, 32 + 12 + 2 + 4 + 11.072);
eq('вклад занятия', L.contrib, 200 - 61.072);
eq('прибыль занятия = вклад − доля постоянных', L.profit, 200 - 61.072 - 60);

/* ================= минимальная наполняемость =================
   Ради этого раздел и делался: «Курсы и маржа» считают порог только по ЗП (32/25 → 2 ребёнка),
   а с налогами и долей постоянных картина совсем другая. */
eq('минимум детей при лёгкой доле постоянных (60 р)', API._fmlMinFill('Скретч', 60), 4);
eq('минимум детей при тяжёлой доле постоянных (150 р)', API._fmlMinFill('Скретч', 150), 9);
check('порог по одной только ЗП сильно оптимистичнее', Math.ceil(32 / 25) === 2);
check('без постоянных курс в плюсе уже с 4 детей', API._fmlLesson('Скретч', 4, 0).profit > 0);
eq('неподъёмная доля постоянных — порога нет', API._fmlMinFill('Скретч', 100000), null);

/* ================= склонение ================= */
eq('1 ребёнок', API._fmlKid(1), 'ребёнок');
eq('2 ребёнка', API._fmlKid(2), 'ребёнка');
eq('5 детей', API._fmlKid(5), 'детей');
eq('11 детей (не «ребёнок»)', API._fmlKid(11), 'детей');
eq('49 детей', API._fmlKid(49), 'детей');
eq('22 ребёнка', API._fmlKid(22), 'ребёнка');

/* ================= отрисовка ================= */
const h = API._formulaHtml();
check('три единицы на месте', h.indexOf('Одно занятие') > 0 && h.indexOf('Одна группа') > 0 && h.indexOf('Один ребёнок') > 0);
check('разбор занятия отрисован', h.indexOf('Разбор одного занятия') > 0);
check('таблица наполняемости отрисована', h.indexOf('Какая наполняемость зарабатывает') > 0);
check('порог нуля отрисован', h.indexOf('Порог нуля') > 0);
check('курсы из расписания в выпадающем списке', h.indexOf('Скретч') > 0 && h.indexOf('Роблокс') > 0);
check('нет undefined в разметке', h.indexOf('undefined') < 0,
      h.slice(Math.max(0, h.indexOf('undefined') - 120), h.indexOf('undefined') + 80));
check('нет NaN в разметке', h.indexOf('NaN') < 0,
      h.slice(Math.max(0, h.indexOf('NaN') - 120), h.indexOf('NaN') + 80));
check('баланс <div>', (h.match(/<div/g) || []).length === (h.match(/<\/div>/g) || []).length,
      'открыто ' + (h.match(/<div/g) || []).length + ', закрыто ' + (h.match(/<\/div>/g) || []).length);
check('баланс <table>', (h.match(/<table/g) || []).length === (h.match(/<\/table>/g) || []).length);

// доля постоянных 83 р кладёт «Скретч» при 5 детях на −0,21: округление даёт «-0» и читается
// как поломка вёрстки, поэтому у самого нуля печатаем ровный 0
ctx._expenseData = () => Object.assign({}, E, { fixY: 8300, totalY: 16300, profitY: 3700 });
eq('клетка у самого нуля действительно отрицательная', API._fmlLesson('Скретч', 5, 83).profit, -0.208);
const hz = API._formulaHtml();
check('прибыль у самого нуля пишется как 0, а не «-0»', hz.indexOf('>-0<') < 0 && hz.indexOf('>0<') > 0,
      hz.indexOf('>-0<') >= 0 ? 'нашлось «-0»' : 'не нашлось ожидаемого нуля');
ctx._expenseData = () => E;

// расписание пустое — вместо цифр понятное объяснение, а не поломка
ctx._expenseData = () => ({ ym: '', mN: 0, months: [], all: [], mo: n => n });
const h0 = API._formulaHtml();
check('без запущенных групп — объяснение, а не ошибка', h0.indexOf('нет ни одной запущенной группы') > 0);
ctx._expenseData = () => E;

/* ================= факт расходов от бухгалтера =================
   Жанна вбивает расходы по статьям в «Расходы ежедневно» в конце месяца. Постоянные должны
   прийти оттуда, а переменные (ЗП, налоги) остаться модельными — иначе задвоятся. */
eq('«Аренда» по умолчанию постоянная', API._rashodKindDefault('Аренда'), 'fix');
eq('«ЗП педагогов» по умолчанию переменная', API._rashodKindDefault('ЗП педагогов'), 'var');
eq('«Налог» по умолчанию переменный', API._rashodKindDefault('Налог'), 'var');
eq('«ФСЗН» по умолчанию переменный', API._rashodKindDefault('ФСЗН'), 'var');
eq('«Комиссии за списание» — переменные', API._rashodKindDefault('Комиссии за списание'), 'var');
eq('«Реклама» — постоянная (решает Жанна)', API._rashodKindDefault('Реклама'), 'fix');
ctx.S.rashodKind = { 'Реклама': 'var' };
eq('переключатель перебивает умолчание', API.rashodKind('Реклама'), 'var');
delete ctx.S.rashodKind;

check('месяца без расходов нет в факте', API._rashodFact('2026-09') === null);

// сентябрь: часть прошла через Alfa, часть вписана руками по списку бухгалтера
ctx._paySnap = {
  '2026-09-03': { byItem: { 'Аренда': 3000, 'Реклама': 500 } },
  '2026-09-17': { byItem: { 'Аренда': 1000, 'Сервисы': 200 } },
  '2026-10-05': { byItem: { 'Аренда': 4000 } },
};
ctx.S.rashodManual = { '2026-09': { 'ЗП педагогов': 9000, 'Налог': 1800, 'Коммунальные платежи': 700 } };
const F9 = API._rashodFact('2026-09');
eq('Alfa и ручной ввод сложились', F9.total, 3000 + 500 + 1000 + 200 + 9000 + 1800 + 700);
eq('постоянные: аренда + реклама + сервисы + коммуналка', F9.fix, 4000 + 500 + 200 + 700);
eq('переменные: ЗП + налог', F9.vr, 9000 + 1800);
eq('статьи отсортированы по убыванию', F9.items.fix[0].n, 'Аренда');

// одна статья в двух источниках складывается, а не перетирается
ctx.S.rashodManual['2026-09']['Аренда'] = 500;
eq('одна статья из Alfa и вручную суммируется', API._rashodFact('2026-09').fix, 4000 + 500 + 500 + 200 + 700);
delete ctx.S.rashodManual['2026-09']['Аренда'];

// средний месяц: считается только по тем месяцам, где факт уже есть
const FA = API._rashodFactAvg(MONTHS);
eq('в среднем учтены оба месяца', FA.months, 2);
eq('средние постоянные', FA.fix, (5400 + 4000) / 2);
eq('средние переменные', FA.vr, (10800 + 0) / 2);

/* --- формула считает постоянные по факту --- */
const DF = API._fmlData('2026-09');
eq('источник постоянных — факт', DF.fixSrc, 'fact');
eq('постоянные месяца = факт', DF.month.fix, 5400);
eq('переменные остались модельными (не задвоились)', DF.month.vr, 4000);
eq('прибыль месяца пересчиталась по факту', DF.month.profit, 10000 - 4000 - 5400);
eq('доля постоянных на занятие — от факта', DF.lesson.fix, 5400 / 50);

ctx._paySnap = null; ctx.S.rashodManual = {};
eq('без факта — снова модель', API._fmlData('2026-09').fixSrc, 'model');
eq('без факта постоянные из модели', API._fmlData('2026-09').month.fix, 4500);

/* --- плашка источника --- */
const hM = API._formulaHtml();
check('без факта сказано, что взята модель', hM.indexOf('взяты <b>из модели</b>') > 0);
ctx._paySnap = { '2026-09-03': { byItem: { 'Аренда': 5000 } } };
ctx.S.rashodManual = { '2026-09': { 'ЗП педагогов': 12000 } };
ctx._fmlMonth = '2026-09';
const hF = API._formulaHtml();
check('с фактом сказано, откуда постоянные', hF.indexOf('ваш факт по статьям') > 0);
// средний месяц: видно, по каким месяцам усреднили, и человеческими названиями
ctx._fmlMonth = ''; ctx._paySnap = null;
ctx.S.rashodManual = { '2026-09': { 'Аренда': 5000 }, '2026-10': { 'Аренда': 7000 } };
const hAvg = API._formulaHtml();
check('в среднем месяце перечислены месяцы с фактом', hAvg.indexOf('среднее по 2 мес.: сен, окт') > 0,
      hAvg.slice(hAvg.indexOf('среднее по'), hAvg.indexOf('среднее по') + 90));
eq('средние постоянные из двух месяцев', API._fmlData('').month.fix, 6000);
ctx._fmlMonth = '2026-09'; ctx._paySnap = { '2026-09-03': { byItem: { 'Аренда': 5000 } } };
ctx.S.rashodManual = { '2026-09': { 'ЗП педагогов': 12000 } };
check('показано расхождение по переменным', hF.indexOf('Расхождение') > 0);
check('переменные факта не прибавлены к прибыли', hF.indexOf('иначе одно и то же посчиталось бы дважды') > 0);
ctx._fmlMonth = ''; ctx._paySnap = null; ctx.S.rashodManual = {};

console.log(bad ? '\n❌ провалено проверок: ' + bad : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
