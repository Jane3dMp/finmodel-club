// Заполняемость групп (детоместа) и ход к цели месяца 150/180.
// Запуск: node backend/test-fill.cjs
//
// Проверяется НАСТОЯЩИЙ код из index.html — функции вырезаются из файла и исполняются.
// Серверная часть (сбор детомест из Alfa) проверяется отдельно: php backend/test-fill-seats.php
//
// Правило, на котором всё держится: детоместо — это СПИСАНИЕ, а не присутствие. Ребёнок
// пришёл по абонементу или пропустил, но списание за пропуск прошло — место оплачено.
// Пробные места в загрузку не входят: у них своя цена.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const MULTI = ['_fillGroupName', '_fillPeriods', '_fillCur', '_fillAgg', '_fillCap', 'fillPlanSet',
               '_fillRows', '_fillTotals', '_fillModelPlan', '_fillHtml',
               '_salesCfg', '_salesPace', '_salesPaceLines', '_salesPaceHtml'];
const ONE = ['_fillIdx', '_fillGroups', '_fillSubjName', '_fillTeachName', '_fillPctColor',
             '_fillRefresh', 'fillGo', 'fillSortBy', 'fillBaseSet', '_fillPlanMap',
             '_salesGoals', '_salesNum', '_salesDate', '_salesMonName', '_salesMonday'];
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of MULTI) grab(n, new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
for (const n of ONE) grab(n, new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));

/* --- хранилище детомест: неделя 31.08–06.09 --- */
/* формат дня: id группы → [les, seats, paid, trial, att, rev] */
const FILL = {
  '2026-09-01': { '10': [1, 7, 7, 0, 6, 210], '20': [1, 5, 4, 1, 4, 120] },
  '2026-09-03': { '10': [1, 8, 8, 0, 7, 240], '20': [1, 6, 5, 0, 5, 150] },
  '2026-09-05': { '0':  [1, 1, 1, 0, 1, 46] },          // индивидуальное занятие
  '2026-03-02': { '10': [1, 5, 5, 0, 5, 150] },          // март — эталонный месяц
};
const ctx = {
  S: { fillPlan: { '20': 6 }, plan: [{ name: 'Английский', perGroup: 6, groups: 3, visits: 2 }],
       assume: { weeksPerMonth: 4 }, salesCfg: null, fillBase: '2026-03' },
  _fillStore: { fill: FILL, fmt: ['les', 'seats', 'paid', 'trial', 'att', 'rev'],
                groups: { '10': { name: 'Английский №1', subject: 11, teacher: 5, limit: 8 },
                          '20': { name: 'Scratch №2', subject: 12, teacher: 6, limit: 10 } },
                groupsOk: true, subjects: { '11': 'Английский язык', '12': 'Scratch' },
                teachers: { '5': 'Бурдук Наталья', '6': 'Козырев Влад' } },
  _fillPeriod: 'w:2026-08-31', _fillSort: 'pct', _fillErr: null, _fillBusy: false,
  _FILL_FMT: ['les', 'seats', 'paid', 'trial', 'att', 'rev'],
  _rukSec: 'fill',
  _RU_MON: ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'],
  _todayIso: () => '2026-09-07',
  _dIso: d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'),
  esc: s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  _jsStr: s => String(s == null ? '' : s),
  _gm: n => String(Math.round(+n || 0)),
  _kassaDayWord: n => (n === 1 ? 'день' : 'дней'),
  shortName: s => String(s),
  courseFromLib: name => ({ name: name, visits: 2, price: 25 }),
  persistLocal: () => {},
  _pubErrHtml: e => '<div class="callout">Не получилось: ' + ((e && e.message) || e) + '</div>',
  document: { getElementById: () => null },
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_fillAgg,_fillCap,_fillRows,_fillTotals,_fillModelPlan,_fillHtml,_fillPeriods,_fillCur,' +
  '_fillPlanMap,fillPlanSet,_salesPace,_salesPaceLines,_salesPaceHtml,_salesCfg,_salesGoals}; }')(ctx);

/* ================= 1. свод по группам за неделю ================= */
const agg = API._fillAgg('2026-08-31', '2026-09-06');
eq('дней недели в хранилище', agg.days, 3);
eq('из них с занятиями', agg.lessonDays, 3);
eq('март в неделю не попал', agg.groups['10'].les, 2);
eq('детоместа группы 10 (списания)', agg.groups['10'].paid, 15);
eq('пробное место группы 20 отмечено', agg.groups['20'].trial, 1);
eq('пик группы 10 — лучший день', agg.groups['10'].peak, 8);

/* ================= 2. откуда берётся «сколько должно быть» ================= */
eq('вписанный план важнее вместимости Alfa', API._fillCap('20', agg).cap, 6);
eq('и это видно по источнику', API._fillCap('20', agg).src, 'plan');
eq('плана нет — берём вместимость группы в Alfa', API._fillCap('10', agg).cap, 8);
eq('источник — Alfa', API._fillCap('10', agg).src, 'alfa');
eq('ни плана, ни Alfa — лучший день группы', API._fillCap('0', agg).cap, 1);
eq('источник — пик', API._fillCap('0', agg).src, 'peak');

/* ================= 3. загрузка ================= */
const rows = API._fillRows(agg), byId = {};
rows.forEach(r => { byId[r.gid] = r; });
eq('детоместа по абонементу = списания − пробные', byId['20'].fact, 8);
eq('мест было = вместимость × занятий', byId['20'].seats, 12);
eq('загрузка группы 20', Math.round(byId['20'].pct), 67);
eq('загрузка группы 10', Math.round(byId['10'].pct), 94);
eq('название группы из Alfa', byId['10'].name, 'Английский №1');
eq('курс группы', byId['10'].subj, 'Английский язык');
eq('индивидуальные названы отдельно', byId['0'].name, 'Индивидуальные (вне групп)');
check('сначала самые пустые', rows[0].gid === '20', 'первым идёт ' + rows[0].gid);

const T = API._fillTotals(rows);
eq('мест по клубу', T.seats, 29);
eq('детомест по клубу', T.fact, 24);
eq('загрузка клуба', Math.round(T.pct), 83);
eq('пришли (для сверки с пропусками)', T.att, 23);

/* группа с планом, но без единого занятия за период, из таблицы не пропадает:
   иначе исчезнувшая группа выглядела бы как «всё в порядке» */
ctx.S.fillPlan['77'] = 8;
const rows2 = API._fillRows(agg);
check('группа с планом и без занятий осталась строкой', rows2.some(r => r.gid === '77'),
      'групп: ' + rows2.map(r => r.gid).join(','));
eq('и в проценты она не лезет', API._fillTotals(rows2).seats, 29);
delete ctx.S.fillPlan['77'];

/* ================= 4. план детомест из финмодели ================= */
const MP = API._fillModelPlan();
eq('мест по планировщику', MP.slots, 18);              // 6 чел × 3 группы
eq('детомест в неделю (× визиты)', MP.seatsWeek, 36);  // × 2 визита

/* ================= 5. эталонный месяц ================= */
const bAgg = API._fillAgg('2026-03-01', '2026-03-31');
const B = API._fillTotals(API._fillRows(bAgg));
eq('март: мест было', B.seats, 8);                     // одно занятие × вместимость 8
eq('март: детомест', B.fact, 5);
eq('март: загрузка', Math.round(B.pct), 63);

/* ================= 6. ход к цели месяца ================= */
const rep = { week: '2026-08-31', fact: 22578, pace: {
  ym: '2026-09', fact: 30000, forecast: 120000, studyDays: 26,
  nextYm: '2026-10', nextForecast: 130000,
  seats: 900, kids: 150, seatDays: 6, lessonDays: 6 } };
const P = API._salesPace(rep);
eq('идём к ближайшей непобитой цели', P.goal, 150000);
eq('выполнение прогнозом', Math.round(P.pct), 80);
eq('не хватает', P.gap, 30000);
eq('средний чек за детоместо', Math.round(P.perSeat * 100) / 100, 33.33);
eq('занятий у ребёнка за ПОЛНЫЙ месяц', Math.round(P.seatsPerKid), 26);
eq('доход с ребёнка за месяц', Math.round(P.perKid), 867);
eq('сколько детей добрать', P.needKids, 35);
check('в сообщении названы цель и прогноз',
      API._salesPaceLines(rep).join('\n').indexOf('Цель месяца 150.000') >= 0,
      API._salesPaceLines(rep).join(' | '));
check('в сообщении сказано, сколько детей',
      /~35 новых детей/.test(API._salesPaceLines(rep).join('\n')),
      API._salesPaceLines(rep).join(' | '));

/* цель побита — идём к следующей */
const rep2 = JSON.parse(JSON.stringify(rep)); rep2.pace.forecast = 160000;
eq('после 150 идём к 180', API._salesPace(rep2).goal, 180000);
const rep3 = JSON.parse(JSON.stringify(rep)); rep3.pace.forecast = 200000;
eq('обе цели взяты — считаем от большей', API._salesPace(rep3).goal, 180000);
eq('перевыполнение не даёт отрицательной нехватки', API._salesPace(rep3).gap, 0);
check('и в сообщении это сказано словами',
      /перекрыта/.test(API._salesPaceLines(rep3).join('\n')), API._salesPaceLines(rep3).join(' | '));

/* ⚠️ Счётчики детомест есть не по всем дням месяца — «сколько детей добрать» не выдумываем.
   Половина месяца дала бы средний чек по одной половине и детей по обеим. */
const rep4 = JSON.parse(JSON.stringify(rep)); rep4.pace.seatDays = 3;
const P4 = API._salesPace(rep4);
check('неполные счётчики — детей не считаем', P4.needKids === null && P4.full === false, JSON.stringify(P4));
check('но нехватку до цели показываем всё равно',
      /До цели 30.000\.$/m.test(API._salesPaceLines(rep4).join('\n')), API._salesPaceLines(rep4).join(' | '));
check('и объясняем, почему цифры нет',
      /Пересчитать заново/.test(API._salesPaceHtml(rep4)), 'нет подсказки о пересчёте');

/* ================= 7. вёрстка собирается ================= */
const h = API._fillHtml();
check('таблица отрисовалась', h.indexOf('Английский №1') > 0, h.slice(0, 200));
check('итог по клубу в вёрстке', h.indexOf('ИТОГО') > 0);
check('план финмодели показан', h.indexOf('Купленные детоместа') > 0);
check('эталон показан', h.indexOf('Эталон загрузки') > 0);
check('в блоке эталона названа загрузка марта', /63%/.test(h), 'нет 63%');
check('вёрстка без «undefined»', h.indexOf('undefined') < 0, h.slice(Math.max(0, h.indexOf('undefined') - 120), h.indexOf('undefined') + 60));

/* незасчитанные дни периода честно названы */
ctx._fillStore.fill = { '2026-09-01': FILL['2026-09-01'] };
check('о непосчитанных днях предупреждаем', /ещё не считали/.test(API._fillHtml()), 'нет предупреждения');
ctx._fillStore.fill = FILL;

console.log(bad ? '\nПРОВАЛЕНО: ' + bad : '\nВсё сошлось');
process.exit(bad ? 1 : 0);
