// Отчёт для отдела продаж: сборка текста сообщения и рейтинг педагогов.
// Запуск: node backend/test-salesreport.cjs
//
// Проверяется НАСТОЯЩИЙ код из index.html — функции вырезаются из файла и исполняются.
// Серверная (расчётная) часть проверяется отдельно: php backend/test-salesreport.php
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const NAMES = ['_salesNum', '_salesCfg', '_ratAgg', '_ratSubjToCourse', '_ratModelTeacher',
               'fixRateByName', '_ratWage', '_ratTeacherName',
               '_salesTops', '_salesWeekText', '_salesMonthText',
               '_salesReports', '_salesCur', '_salesDate', '_salesMonName', '_salesHtml', '_salesCronHtml',
               '_trialKidName', '_trialsHtml'];
const ONE_LINERS = ['_salesMonthEnd', '_salesMonday'];   // тело в одну строку — своя регулярка
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of NAMES) grab(n, new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
for (const n of ONE_LINERS) grab(n, new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));

/* --- ставки: база 20 за курс при 4 детях, +3 за каждого следующего ребёнка --- */
const wage = (course, kids) => (course === 'Робототехника' ? 20 + 3 * (Math.max(4, kids) - 4) : 0);
/* --- дневные снимки реализации: у педагога id=5 — 4 урока по 8 детей, у 6 — 20 по 6, у 7 — 2 по 12 --- */
function day(list) {
  const bt = {};
  list.forEach(([tid, kids, revenue]) => {
    bt[tid] = { lessons: 1, minutes: 60, seats: kids, revenue: revenue, rows: { ['11|' + kids + '|60']: 1 } };
  });
  return { present: 0, all: 0, planned: 0, lessons: list.length, byTeacher: bt };
}
const store = {};
for (let d = 1; d <= 20; d++) {
  const iso = '2025-10-' + String(d).padStart(2, '0');
  const rows = [[6, 6, 90]];                       // Козырев: каждый из 20 дней
  if (d <= 4) rows.push([5, 8, 120]);              // Титова: 4 урока
  if (d <= 2) rows.push([7, 12, 180]);             // разовая подмена: 2 урока
  store[iso] = day(rows);
}
store['2025-09-30'] = day([[5, 8, 999]]);          // вне периода — не должен попасть в месяц

const ctx = {
  S: { salesCfg: null },
  _realStore: store,
  _RU_MON: ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'],
  _pubMap: () => ({ subj: { 'Робототехника': 11 } }),
  _pubRefList: () => [{ id: 5, name: 'Титова Злата' }, { id: 6, name: 'Козырев Влад' }, { id: 7, name: 'Разовый Педагог' }],
  wageForLesson: wage,
  persistLocal: () => {},
  // однострочные в index.html — регуляркой не вырезаются, повторяем один в один
  _dIso: d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'),
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  _jsStr: s => String(s == null ? '' : s),
  _gm: n => String(Math.round(+n || 0)),
  _pubErrHtml: e => '<div class="callout">Не получилось: ' + ((e && e.message) || e) + '</div>',
  _salesStore: null, _salesWeek: null, _salesErr: null,
  _todayIso: () => '2025-11-05',
  _trialMonth: '2025-10', _salesRefresh: () => {},
  location: { origin: 'https://app.proznanie.club' },
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_salesNum,_salesTops,_salesWeekText,_salesMonthText,_salesMonthEnd,_salesCfg,_salesHtml,_trialsHtml}; }')(ctx);

/* ================= формат чисел (как в чате: 22.578) ================= */
eq('число с разделителем тысяч', API._salesNum(22578), '22.578');
eq('шестизначное', API._salesNum(102117.4), '102.117');
eq('меньше тысячи — без точки', API._salesNum(617), '617');

/* ================= рейтинг педагогов ================= */
const tops = API._salesTops('2025-10-01', '2025-10-31');
eq('конец месяца', API._salesMonthEnd('2025-10'), '2025-10-31');
eq('дней с данными за октябрь', tops.days, 20);
// Титова: 4 урока × (8 детей — ставка 32) → прибыль 4×120−128 = 352, за урок 88
// Козырев: 20 × (6 детей — ставка 26) → 20×90−520 = 1280, за урок 64
// Разовый: 2 × (12 детей — ставка 44) → 2×180−88 = 272, за урок 136 — но уроков меньше 4
eq('топ «за одно занятие» — первый', tops.perLesson[0].name, 'Титова Злата');
eq('топ «за одно занятие» — второй', tops.perLesson[1].name, 'Козырев Влад');
check('разовая подмена не попала в топ за занятие (порог 4 урока)',
      tops.perLesson.length === 2, 'в списке: ' + tops.perLesson.map(r => r.name).join(', '));
eq('топ «за месяц» — первый', tops.perMonth[0].name, 'Козырев Влад');
eq('прибыль Козырева за месяц', Math.round(tops.perMonth[0].val), 1280);
eq('прибыль Титовой за занятие', Math.round(tops.perLesson[0].perLesson), 88);
check('сентябрьский день не попал в октябрь',
      Math.round(tops.perMonth.find(r => r.name === 'Титова Злата').revenue) === 480,
      'выручка Титовой = ' + tops.perMonth.find(r => r.name === 'Титова Злата').revenue);
check('все занятия со ставкой', tops.noRate === 0, 'без ставки: ' + tops.noRate);

// по выручке порядок другой: у Козырева выручки больше всех, но за урок — меньше
ctx.S.salesCfg = { metric: 'revenue', top: 10, minLessons: 4 };
const topsRev = API._salesTops('2025-10-01', '2025-10-31');
eq('по выручке за урок — Титова (120) выше Козырева (90)', topsRev.perLesson[0].name, 'Титова Злата');
eq('по выручке за месяц — Козырев', topsRev.perMonth[0].name, 'Козырев Влад');
eq('выручка Козырева за месяц', Math.round(topsRev.perMonth[0].val), 1800);

// курс не сопоставлен с предметом Alfa → ставки нет, это должно быть видно
ctx._pubMap = () => ({ subj: {} });
ctx.S.salesCfg = { metric: 'profit', top: 10, minLessons: 4 };
check('занятия без ставки посчитаны', API._salesTops('2025-10-01', '2025-10-31').noRate === 26,
      'без ставки: ' + API._salesTops('2025-10-01', '2025-10-31').noRate);
ctx._pubMap = () => ({ subj: { 'Робототехника': 11 } });

/* фикс-режим ЗП: ставка у педагога за занятие такой длительности, число детей не при чём.
   До правки расчёт молча уезжал на KPI-сетку — в чат уходила бы неверная прибыль. */
ctx.S._wageMode = 'fix';
ctx.S._fixRates = { 'Козырев Влад': { 60: 25 }, 'Титова Злата': { 90: 40 } };   // у Титовой только 90 мин
ctx._pubMap = () => ({ subj: { 'Робототехника': 11 }, teacher: { 'Титова Злата': 5, 'Козырев Влад': 6 } });
const fx = API._salesTops('2025-10-01', '2025-10-31');
eq('фикс: ЗП Козырева = 20 уроков × 25', Math.round(fx.perMonth.find(r => r.name === 'Козырев Влад').zp), 500);
eq('фикс: у Титовой берётся ближайшая заданная длительность (90 → 40)',
   Math.round(fx.perMonth.find(r => r.name === 'Титова Злата').zp), 160);
const noMap = fx.perMonth.find(r => r.name === 'Разовый Педагог');
check('фикс: педагог без сопоставления с Alfa — занятия помечены как без ставки',
      noMap.zp === 0 && noMap.noRate === 2, JSON.stringify({ zp: noMap.zp, noRate: noMap.noRate }));
delete ctx.S._wageMode; delete ctx.S._fixRates;
ctx._pubMap = () => ({ subj: { 'Робототехника': 11 } });

/* ================= текст недельного сообщения ================= */
const rep = {
  week: '2025-10-06', to: '2025-10-12', fact: 22578.4, goal: 22500,
  next: { week: '2025-10-13', forecast: 23749, suggest: 21300, src: 'snapshot' },
  active: 624, activePrev: 639, man: {},
};
eq('недельное сообщение', API._salesWeekText(rep),
   'Оборот недели: 22.578.\nШли на 22.500.\n\nПрогноз на след неделю: 23.749.\nИдем на 21.300.\n\nАктивных клиентов: 624 (-15).');

rep.man = { nextGoal: 22000, note: 'Отсев после первого абонемента' };
eq('своя цель перебивает предложенную и комментарий в конце', API._salesWeekText(rep),
   'Оборот недели: 22.578.\nШли на 22.500.\n\nПрогноз на след неделю: 23.749.\nИдем на 22.000.\n\nАктивных клиентов: 624 (-15).\n\nОтсев после первого абонемента');

const first = { week: '2025-09-01', to: '2025-09-07', fact: 18000, goal: 0,
                next: { forecast: 20000, suggest: 18000 }, active: 0, activePrev: 0, man: {} };
eq('первый отчёт: без «шли на» и без клиентов', API._salesWeekText(first),
   'Оборот недели: 18.000.\n\nПрогноз на след неделю: 20.000.\nИдем на 18.000.');

const grow = Object.assign({}, rep, { active: 674, activePrev: 661, man: {} });
check('рост клиентов со знаком плюс', API._salesWeekText(grow).indexOf('Активных клиентов: 674 (+13).') > 0,
      API._salesWeekText(grow));

/* ================= текст месячного сообщения ================= */
const mrep = {
  week: '2025-10-27', to: '2025-11-02', fact: 22000, goal: 22000,
  next: { forecast: 26475, suggest: 23000, src: 'snapshot' }, active: 672, activePrev: 668,
  month: { ym: '2025-10', fact: 96568, goal: 89000, nextYm: '2025-11', forecast: 117816,
           suggest: 107000, activePrev: 617 },
  man: {},
};
ctx.S.salesCfg = { metric: 'profit', top: 2, minLessons: 4 };
const t2 = API._salesTops('2025-10-01', '2025-10-31');
const mt = API._salesMonthText(mrep, t2);
eq('месячное сообщение', mt,
  'Шли на 89.000.\nОборот месяца: 96.568.\n\n' +
  'Прогноз на след месяц: 117.816.\nИдем на 107.000.\n\n' +
  'Активных клиентов: 672 (+55).\n\n' +
  'Рейтинг педагогов, которые приносят максимальную прибыль за одно занятие (по убыванию от максимума):\n' +
  ' Титова Злата\n Козырев Влад\n\n' +
  'Рейтинг педагогов, которые приносят максимальную прибыль за месяц (по убыванию от максимума):\n' +
  ' Козырев Влад\n Титова Злата');
check('без месячного блока текста нет', API._salesMonthText(rep, t2) === '', 'вернулось не пустое');

/* ================= отрисовка раздела (смоук: шаблон не разваливается) ================= */
function render(state) { Object.assign(ctx, state); return API._salesHtml(); }

const hLoad = render({ _salesStore: null, _salesErr: null });
check('пока грузится — видно, что грузится', hLoad.indexOf('Загружаю отчёты') > 0, hLoad.slice(0, 200));

const hErr = render({ _salesErr: new Error('нет такого действия') });
check('ошибка показывается с кнопкой «собрать»', hErr.indexOf('нет такого действия') > 0 && hErr.indexOf('salesBuild()') > 0);

const hEmpty = render({ _salesErr: null, _salesStore: { reports: {}, activeLog: {}, settings: {} } });
check('пустое хранилище — предлагаем собрать', hEmpty.indexOf('Отчётов пока нет') > 0);
check('в пустом состоянии есть настройка cron', hEmpty.indexOf('cron_salesreport.php') > 0);

ctx.S.salesCfg = { metric: 'profit', top: 10, minLessons: 4 };
const hFull = render({ _salesStore: { reports: { '2025-10-06': rep, '2025-10-27': mrep }, activeLog: {},
                                      settings: { lastRun: '2025-11-02T22:00:11+03:00' } }, _salesWeek: '2025-10-27' });
check('выбранная неделя — с месячным блоком', hFull.indexOf('Сообщение по итогам месяца') > 0);
check('в тексте сообщения — оборот месяца', hFull.indexOf('Оборот месяца: 96.568.') > 0);
check('рейтинг педагогов отрисовался', hFull.indexOf('Козырев Влад') > 0);
check('поле «идём на» с подсказкой', hFull.indexOf("salesSet('2025-10-27','nextGoal',this.value)") > 0);
check('переключатель прибыль/выручка на месте', hFull.indexOf("salesCfgSet('metric',this.value)") > 0);
check('нет undefined в разметке', hFull.indexOf('undefined') < 0, hFull.slice(Math.max(0, hFull.indexOf('undefined') - 120), hFull.indexOf('undefined') + 80));
check('нет NaN в разметке', hFull.indexOf('NaN') < 0, hFull.slice(Math.max(0, hFull.indexOf('NaN') - 120), hFull.indexOf('NaN') + 80));
check('теги закрыты (баланс <div>)', (hFull.match(/<div/g) || []).length === (hFull.match(/<\/div>/g) || []).length,
      'открыто ' + (hFull.match(/<div/g) || []).length + ', закрыто ' + (hFull.match(/<\/div>/g) || []).length);
check('баланс <table>', (hFull.match(/<table/g) || []).length === (hFull.match(/<\/table>/g) || []).length);

// на экране должно быть видно, ОТКУДА цифра — иначе снова придётся спрашивать
check('оборот подписан как среднее с пропусками и без', hFull.indexOf('среднее дохода с пропусками и без пополам') > 0);
check('прогноз недели подписан как из «Прогноза по всем»', hFull.indexOf('«🔮 Прогноз по всем»') > 0);
check('прогноз месяца подписан как разложенный недельный', hFull.indexOf('разложенный по дням недели') > 0);

const hNoSnap = render({ _salesStore: { reports: { '2025-10-06': Object.assign({}, rep, { next: { forecast: 23749, suggest: 21300 } }) },
                                        activeLog: {}, settings: {} }, _salesWeek: null });
check('без снимка недели — честно сказано, что цифра может отличаться',
      hNoSnap.indexOf('может чуть отличаться') > 0);

check('за текущую неделю отчёта нет — предлагаем собрать', hFull.indexOf('Собрать за текущую неделю') > 0);
const hHasCur = render({ _salesStore: { reports: { '2025-11-03': rep }, activeLog: {}, settings: {} }, _salesWeek: null });
check('отчёт за текущую неделю уже есть — лишней кнопки нет', hHasCur.indexOf('Собрать за текущую неделю') < 0);

const hWeekOnly = render({ _salesStore: { reports: { '2025-10-06': rep, '2025-10-27': mrep }, activeLog: {},
                                          settings: { lastRun: '2025-11-02T22:00:11+03:00' } }, _salesWeek: '2025-10-06' });
check('обычная неделя — без месячного блока', hWeekOnly.indexOf('Сообщение по итогам месяца') < 0);
check('обычная неделя — недельный текст на месте', hWeekOnly.indexOf('Оборот недели: 22.578.') > 0);

console.log(bad ? '\n❌ провалено проверок: ' + bad : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
