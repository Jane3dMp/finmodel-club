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
const MULTI = ['_fillGroupName', '_fillAcadYear', '_fillYearWeeks', '_fillPeriods', '_fillPeriodOpts', '_fillCur',
               '_fillWho', '_fillKidsCur', '_fillKidsHtml', '_fillCats', 'fillCatSet',
               '_fillSubjName', '_fillTeach', '_fillTopId',
               '_fillPlanPerGroup',
               '_fillAgg', '_fillCap', 'fillPlanSet',
               '_fillRows', '_fillTotals', '_fillModelPlan', '_fillHtml',
               '_salesCfg', '_salesPace', '_salesPaceLines', '_salesPaceHtml'];
const ONE = ['_fillIdx', '_fillGroups', '_fillPctColor',
             '_fillTeachName',
             '_fillAcadMonth', '_fillArch', '_fillNoName',
             '_fillRefresh', 'fillGo', 'fillSortBy', 'fillBaseSet', '_fillPlanMap',
             '_salesGoals', '_salesNum', '_salesDate', '_salesMonName', '_salesMonday'];
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of ['_FILL_CATS']) grab(n, new RegExp('\\nconst ' + n + '=[\\s\\S]*?\\n\\];', 'm'));
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
  _rukPokaz: () => ({ curYear: '2026/27' }),
  _pubMap: () => ({ subj: { 'Английский': 11, 'Scratch': 12 } }),
  _fillKids: null, _fillKidsKey: '', _fillKidsBusy: false, _fillKidsErr: null,
  _trKidLink: id => '<a>' + id + '</a>', _fmlKid: n => 'детей',
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
  '_fillAcadYear,_fillAcadMonth,_fillYearWeeks,_fillPeriodOpts,_fillArch,_fillNoName,_fillGroupName,' +
  '_fillWho,_fillKidsCur,_fillKidsHtml,_fillPlanPerGroup,_fillCap,_fillTeach,_fillSubjName,_fillTopId,' +
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
// вписанного плана нет, но курс есть в планировщике — берём «Чел/гр» оттуда
eq('плана нет — берём «Чел/гр» из планировщика', API._fillCap('10', agg).cap, 6);
eq('источник — планировщик', API._fillCap('10', agg).src, 'model');
// курса нет в планировщике — тогда вместимость из Alfa
ctx.S.plan = [];
eq('без планировщика — вместимость Alfa', API._fillCap('10', agg).cap, 8);
eq('источник — Alfa', API._fillCap('10', agg).src, 'alfa');
ctx.S.plan = [{ name: 'Английский', perGroup: 6, groups: 3, visits: 2 }];
// а вписанный руками план сильнее планировщика: это осознанная правка по конкретной группе
ctx.S.fillPlan['10'] = 9;
eq('ручной план важнее планировщика', API._fillCap('10', agg).cap, 9);
delete ctx.S.fillPlan['10'];
eq('ни плана, ни Alfa — лучший день группы', API._fillCap('0', agg).cap, 1);
eq('источник — пик', API._fillCap('0', agg).src, 'peak');

/* ================= 3. загрузка ================= */
const rows = API._fillRows(agg), byId = {};
rows.forEach(r => { byId[r.gid] = r; });
eq('детоместа по абонементу = списания − пробные', byId['20'].fact, 8);
eq('мест было = вместимость × занятий', byId['20'].seats, 12);
eq('загрузка группы 20', Math.round(byId['20'].pct), 67);
// 15 детомест при плане 6×2 занятия = 12 мест: набрали БОЛЬШЕ плана, и это видно
eq('загрузка группы 10 — от плана, а не от стульев', Math.round(byId['10'].pct), 125);
eq('название группы из Alfa', byId['10'].name, 'Английский №1');
eq('курс группы', byId['10'].subj, 'Английский язык');
eq('индивидуальные названы отдельно', byId['0'].name, 'Индивидуальные (вне групп)');
check('сначала самые пустые', rows[0].gid === '20', 'первым идёт ' + rows[0].gid);

const T = API._fillTotals(rows);
eq('мест по клубу', T.seats, 25);
eq('детомест по клубу', T.fact, 24);
eq('загрузка клуба', Math.round(T.pct), 96);
eq('пришли (для сверки с пропусками)', T.att, 23);

/* группа с планом, но без единого занятия за период, из таблицы не пропадает:
   иначе исчезнувшая группа выглядела бы как «всё в порядке» */
ctx.S.fillPlan['77'] = 8;
const rows2 = API._fillRows(agg);
check('группа с планом и без занятий осталась строкой', rows2.some(r => r.gid === '77'),
      'групп: ' + rows2.map(r => r.gid).join(','));
eq('и в проценты она не лезет', API._fillTotals(rows2).seats, 25);
delete ctx.S.fillPlan['77'];

/* ================= 4. план детомест из финмодели ================= */
const MP = API._fillModelPlan();
eq('мест по планировщику', MP.slots, 18);              // 6 чел × 3 группы
eq('детомест в неделю (× визиты)', MP.seatsWeek, 36);  // × 2 визита

/* ================= 5. эталонный месяц ================= */
const bAgg = API._fillAgg('2026-03-01', '2026-03-31');
const B = API._fillTotals(API._fillRows(bAgg));
eq('март: мест было', B.seats, 6);                     // одно занятие × «Чел/гр» 6
eq('март: детомест', B.fact, 5);
eq('март: загрузка', Math.round(B.pct), 83);

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
check('план финмодели показан', h.indexOf('Детоместа: план финмодели') > 0);
check('эталон показан', h.indexOf('Эталон загрузки') > 0);
check('в блоке эталона названа загрузка марта', /83%/.test(h), 'нет 83%');
check('вёрстка без «undefined»', h.indexOf('undefined') < 0, h.slice(Math.max(0, h.indexOf('undefined') - 120), h.indexOf('undefined') + 60));

/* незасчитанные дни периода честно названы */
ctx._fillStore.fill = { '2026-09-01': FILL['2026-09-01'] };
check('о непосчитанных днях предупреждаем', /ещё не считали/.test(API._fillHtml()), 'нет предупреждения');
ctx._fillStore.fill = FILL;

/* ================= ВЫБОР ПЕРИОДА =================
   Раньше список был «последние 12 недель»: прошлого учебного года в нём не было вовсе, зато
   были июньские недели. Летом группы другие, и сравнивать их с учебными нечестно — Жанна
   попросила лето убрать, а прошлый год добавить целиком. */
{
  const api = API._fillPeriods();
  const wk = api.filter(p => p.kind === 'w');
  const iso = k => k.slice(2);

  // --- этот учебный год: с 1 сентября по сегодняшнюю неделю ---
  const now = wk.filter(p => p.y === 2026);
  eq('первая неделя года — та, что накрывает 1 сентября', iso(now[now.length - 1].k), '2026-08-31');
  eq('последняя — текущая', iso(now[0].k), '2026-09-07');
  eq('свежие сверху', now.length, 2);
  check('будущих недель нет', !now.some(p => p.from > '2026-09-07'), now.map(p => p.from).join(', '));

  // --- прошлый учебный год целиком ---
  const prev = wk.filter(p => p.y === 2025);
  eq('прошлый год начинается с недели 1 сентября 2025', iso(prev[prev.length - 1].k), '2025-09-01');
  check('и доходит до конца мая', prev[0].from >= '2026-05-25' && prev[0].from <= '2026-05-31',
        prev[0].from);
  check('недель за год около сорока', prev.length >= 38 && prev.length <= 40, String(prev.length));

  // --- ЛЕТА НЕТ ---
  // ⚠️ проверяем по обоим краям недели: неделя на стыке мая и июня — учебная, июньская — нет
  const summer = wk.filter(p => {
    const m1 = +p.from.slice(5, 7), m2 = +p.to.slice(5, 7);
    return (m1 >= 6 && m1 <= 8) && (m2 >= 6 && m2 <= 8);
  });
  eq('летних недель в списке нет', summer.length, 0);
  check('и июньской недели тоже', !wk.some(p => p.k === 'w:2026-06-22'), 'w:2026-06-22');
  // а неделя, которая накрывает 31 мая, остаётся — сезон ею заканчивается
  check('неделя на стыке мая и июня осталась',
        wk.some(p => p.from <= '2026-05-31' && p.to >= '2026-05-31'), wk.map(p => p.from).join(', '));

  // --- месяцы: только учебные ---
  eq('март в списке есть', api.some(p => p.k === 'm:2026-03'), true);
  eq('учебный месяц проходит', API._fillAcadMonth('2026-09'), true);
  eq('июль — нет', API._fillAcadMonth('2026-07'), false);
  eq('август — тоже нет', API._fillAcadMonth('2026-08'), false);
  eq('май — учебный', API._fillAcadMonth('2026-05'), true);

  // --- селект разложен по группам, иначе полсотни недель не пролистать ---
  const opts = API._fillPeriodOpts('w:2026-08-31');
  check('группа этого года', opts.indexOf('Учебный год 2026/27') > 0, opts.slice(0, 200));
  check('группа прошлого года', opts.indexOf('Учебный год 2025/26') > 0);
  check('группа месяцев', opts.indexOf('Месяцы') > 0);
  check('выбранная неделя отмечена', opts.indexOf('value="w:2026-08-31" selected') > 0,
        opts.slice(Math.max(0, opts.indexOf('w:2026-08-31') - 60), opts.indexOf('w:2026-08-31') + 80));
  eq('баланс <optgroup>', (opts.match(/<optgroup/g) || []).length, (opts.match(/<\/optgroup>/g) || []).length);

  // --- прошлогоднюю неделю можно выбрать, и она считается ---
  ctx._fillPeriod = 'w:2025-09-01';
  const cur = API._fillCur();
  eq('выбралась именно она', cur.from, '2025-09-01');
  eq('и это неделя прошлого года', cur.y, 2025);
  check('раздел рисуется без ошибок', API._fillHtml().indexOf('undefined') < 0);
  ctx._fillPeriod = 'w:2026-08-31';
}


/* ================= АРХИВНЫЕ ГРУППЫ =================
   Как только стало можно выбрать неделю прошлого учебного года, половина таблицы превратилась
   в «Группа #248»: group/index отдаёт только действующие группы, а прошлогодние в Alfa
   архивные. Сервер теперь добирает их вторым запросом; здесь проверяется, что клиент такие
   строки показывает и помечает, а не выдаёт молчаливое «Группа #N». */
{
  const G = ctx._fillStore.groups;
  G['30'] = { name: 'Scratch №7 (прошлый год)', subject: 12, teacher: 6, limit: 8, archive: 1 };
  FILL['2025-09-02'] = { '30': [1, 8, 8, 0, 8, 240], '99': [1, 6, 6, 0, 6, 180] };  // 99 — карточки нет вовсе

  eq('архивная группа распознана', API._fillArch('30'), true);
  eq('действующая — нет', API._fillArch('10'), false);
  eq('без карточки имя не найдено', API._fillNoName('99'), true);
  eq('у архивной имя есть', API._fillNoName('30'), false);
  eq('«вне групп» — не безымянная', API._fillNoName('0'), false);
  eq('имя архивной берётся из справочника', API._fillGroupName('30'), 'Scratch №7 (прошлый год)');
  eq('без карточки — номер', API._fillGroupName('99'), 'Группа #99');

  ctx._fillPeriod = 'w:2025-09-01';
  const h = API._fillHtml();
  check('архивная группа в таблице', h.indexOf('Scratch №7 (прошлый год)') > 0);
  check('и помечена «арх.»', h.indexOf('>арх.<') > 0, h.slice(Math.max(0, h.indexOf('Scratch №7') - 40), h.indexOf('Scratch №7') + 260));
  check('удалённая группа названа честно', h.indexOf('имя не найдено') > 0, h.slice(Math.max(0, h.indexOf('Группа #99') - 40), h.indexOf('Группа #99') + 260));
  check('у действующих пометки нет', API._fillHtml().split('>арх.<').length === 2);
  ctx._fillPeriod = 'w:2026-08-31';
  delete FILL['2025-09-02']; delete G['30'];
}


/* ================= КТО БЫЛ: С ДЕНЬГАМИ И БЕЗ =================
   Из дневных итогов эту статистику вывести НЕЛЬЗЯ: «пришёл без списания» и «не пришёл, но
   списалось» в один день взаимно гасятся, и разница att − paid показала бы ноль там, где на
   деле есть и то и другое. Поэтому счётчики считаются у источника, а здесь проверяется, что
   клиент их складывает и показывает. */
{
  const ix = { les: 0, seats: 1, paid: 2, trial: 3, att: 4, rev: 5, attPaid: 6, attFree: 7, noAttPaid: 8 };
  const row = (les, seats, paid, trial, att, rev, ap, af, np) => [les, seats, paid, trial, att, rev, ap, af, np];
  const keep = ctx._fillStore.fill;
  // день, где всё сразу: 6 мест, 4 списания (одно пробное), пришли 5 — трое с абонементом,
  // один на пробное, один вообще без списания; и один пропуск со списанием
  ctx._fillStore.fill = { '2026-09-02': { '10': row(1, 6, 4, 1, 5, 120, 3, 1, 1) } };
  ctx._fillPeriod = 'w:2026-08-31';

  ctx._fillStore.fmt = ['les','seats','paid','trial','att','rev','attPaid','attFree','noAttPaid'];
  const agg = API._fillAgg('2026-08-31', '2026-09-06');
  const rows = API._fillRows(agg);
  const W = API._fillWho(rows);
  eq('пришли всего', W.att, 5);
  eq('из них с оплаченным местом', W.paid, 3);
  eq('без списания вовсе', W.free, 1);
  eq('на пробном — остаток', W.trial, 1);            // 5 − 3 − 1
  eq('пропуск со списанием', W.missPaid, 1);
  // ⚠️ ровно тот случай, ради которого счётчики считаются у источника:
  // att − (paid − trial) = 5 − 3 = 2, и по этой разнице «пришёл без денег» не отличить от пропуска
  check('разница att и детомест не заменяет счётчики',
        (rows[0].att - rows[0].fact) === 2 && W.free === 1, 'att=' + rows[0].att + ' fact=' + rows[0].fact);

  const h = API._fillHtml();
  check('плитка «полная цена»', h.indexOf('пришли · полная цена') > 0);
  check('плитка «без списания»', h.indexOf('пришли · без списания') > 0);
  check('плитка про пробные', h.indexOf('пришли · пробное за 15') > 0);
  check('плитка про пропуск со списанием', h.indexOf('пропуск со списанием') > 0);
  check('колонка в таблице', h.indexOf('>Без списания<') > 0);
  check('и объяснено, что это потерянные деньги',
        h.indexOf('Денег за это занятие клуб не получил') > 0 || h.indexOf('клуб не получил') > 0, 'подпись');

  // --- старые дни без счётчиков: молча занижать нельзя ---
  ctx._fillStore.fill = { '2026-09-02': { '10': [1, 6, 4, 1, 5, 120] } };   // короткая строка
  const agg2 = API._fillAgg('2026-08-31', '2026-09-06');
  eq('день со старым форматом в новые не засчитан', agg2.newDays, 0);
  eq('но занятия у него читаются', agg2.lessonDays, 1);
  const W2 = API._fillWho(API._fillRows(agg2));
  eq('счётчики нулевые', W2.paid + W2.free + W2.missPaid, 0);
  check('и об этом сказано вслух', API._fillHtml().indexOf('нажмите «Пересчитать заново»') > 0,
        API._fillHtml().slice(0, 400));

  ctx._fillStore.fill = keep;
  ctx._fillPeriod = 'w:2026-08-31';
}


/* ================= УНИКАЛЬНЫЕ ДЕТИ И СПИСОК «БЕЗ СПИСАНИЯ» =================
   Детоместо — это МЕСТО на одном занятии, а не ребёнок: ходящий три раза в неделю занимает три
   детоместа. Поэтому уникальных детей нельзя получить сложением дневных счётчиков — только
   объединением id, и приходят они отдельным запросом за выбранный период. */
{
  ctx._fillPeriod = 'w:2026-08-31';
  const P0 = API._fillCur();

  // ответа ещё нет — предлагаем посчитать, а не показываем пустоту
  ctx._fillKids = null; ctx._fillKidsKey = '';
  check('без ответа — кнопка «посчитать»', API._fillKidsHtml().indexOf('Посчитать уникальных детей') > 0,
        API._fillKidsHtml());
  eq('и плитки уникальных нет', API._fillHtml().indexOf('>уникальных детей<') > 0, false);

  // ответ пришёл: 30 детей заняли места, двое пришли без списания
  ctx._fillKids = { from: P0.from, to: P0.to, kids: 30, days: 5,
                    free: [501, 502],
                    names: { '501': { name: 'Иванов Пётр' }, '502': { name: 'Сидорова Аня', archived: 1 } } };
  ctx._fillKidsKey = P0.from + '..' + P0.to;

  const h = API._fillKidsHtml();
  check('видно, сколько уникальных', h.indexOf('Дети за период: 30') > 0, h.slice(0, 200));
  check('и сколько без списания', h.indexOf('без списания — 2') > 0, h.slice(0, 240));
  check('имена перечислены', h.indexOf('501') > 0 && h.indexOf('502') > 0);
  check('архивный помечен', h.indexOf('в архиве') > 0, h);
  check('сказано, за сколько дней данные', h.indexOf('по 5 дней') > 0 || h.indexOf('по 5 дн') > 0, h);
  check('объяснено, что место ≠ ребёнок', h.indexOf('место на одном занятии') > 0);

  // ⚠️ вот ради чего всё: перевод мест в детей
  const full = API._fillHtml();
  check('плитка уникальных детей появилась', full.indexOf('>уникальных детей<') > 0);
  check('и мест на ребёнка', full.indexOf('мест на ребёнка') > 0);

  // сменили период — прежний ответ уже не про него и показываться не должен
  ctx._fillPeriod = 'w:2025-09-01';
  eq('чужой период — ответ не подставляется', API._fillKidsCur(), null);
  check('и снова предлагается посчитать', API._fillKidsHtml().indexOf('Посчитать уникальных детей') > 0);
  ctx._fillPeriod = 'w:2026-08-31';

  // никого без списания — список пуст, но блок не врёт «всё плохо»
  ctx._fillKids = { from: P0.from, to: P0.to, kids: 30, days: 5, free: [], names: {} };
  const h2 = API._fillKidsHtml();
  check('без «без списания» так и сказано', h2.indexOf('Пришедших без списания за период нет') > 0, h2);
  check('и в заголовке лишнего нет', h2.indexOf('без списания —') < 0, h2);

  ctx._fillKids = null; ctx._fillKidsKey = '';
}


/* ================= ЧТО СЧИТАТЬ ЗАНЯТЫМ МЕСТОМ =================
   Пришедшие раскладываются по деньгам на четыре корзины, и какие из них считать местом —
   решает Жанна галочками. Ловушек две: разложение появилось позже самих детомест (у старых
   дней его нет, и применять галочки к ним нельзя — загрузка обвалилась бы на ровном месте),
   и «ноль» бывает двух сортов: списание по абонементу с нулевой ценой и вообще без абонемента. */
{
  const keep = ctx._fillStore.fill, keepCats = ctx.S.fillCats;
  ctx._fillStore.fmt = ['les','seats','paid','trial','att','rev','attPaid','attFree','noAttPaid','attTrial','attZero'];
  // 1 занятие, вместимость 10: полная цена 4, пробное 1, за 0 (по абонементу) 2,
  // без списания 1, пропуск со списанием 3
  //   att = 4+1+2+1 = 8 ; paid = 4+1+3 = 8 ; trial = 1 ; attFree = 2+1 = 3 ; attZero = 2
  ctx._fillStore.fill = { '2026-09-02': { '10': [1, 11, 8, 1, 8, 300, 4, 3, 3, 1, 2] } };
  ctx.S.fillPlan = { '10': 10 };
  ctx._fillPeriod = 'w:2026-08-31';

  const rows0 = API._fillRows(API._fillAgg('2026-08-31', '2026-09-06'));
  const r = rows0.find(x => x.gid === '10');
  eq('полная цена', r.attPaid, 4);
  eq('пробное за 15', r.attTrial, 1);
  eq('списание за 0', r.attZero, 2);
  eq('без списания — остаток', r.attNone, 1);       // attFree 3 − attZero 2
  eq('пропуск со списанием', r.noAttPaid, 3);
  eq('детоместа не зависят от галочек', r.fact, 7);  // paid 8 − trial 1

  // умолчание повторяет прежний расчёт: полная цена + пропуски со списанием
  eq('по умолчанию учтено', r.cnt, 4 + 3);
  eq('и загрузка от них', Math.round(r.pct), 70);

  // ⚠️ галочки меняют ТОЛЬКО процент, детоместа остаются собой
  ctx.S.fillCats = { full: 1, trial: 1, zero: 1, none: 1, miss: 1 };
  const rAll = API._fillRows(API._fillAgg('2026-08-31', '2026-09-06')).find(x => x.gid === '10');
  eq('все корзины', rAll.cnt, 4 + 1 + 2 + 1 + 3);
  eq('детоместа те же', rAll.fact, 7);
  eq('загрузка выросла', Math.round(rAll.pct), 110);

  ctx.S.fillCats = { full: 1, trial: 0, zero: 0, none: 0, miss: 0 };
  const rFull = API._fillRows(API._fillAgg('2026-08-31', '2026-09-06')).find(x => x.gid === '10');
  eq('только полная цена', rFull.cnt, 4);
  eq('и её процент', Math.round(rFull.pct), 40);

  // --- галочки в разметке ---
  ctx.S.fillCats = { full: 1, trial: 0, zero: 0, none: 0, miss: 1 };
  const h = API._fillHtml();
  check('блок галочек есть', h.indexOf('Что считать занятым местом') > 0);
  check('все пять категорий', ['полная цена','пробное за 15','списание за 0','без списания','пропуск со списанием']
        .every(t => h.indexOf(t) > 0), 'не хватает категории');
  const onBoxes = (h.match(/checkbox" checked/g) || []).length;
  check('отмечены ровно две категории', onBoxes === 2, 'отмечено: ' + onBoxes);
  check('видно, сколько учтено', h.indexOf('В загрузке учтено') > 0);

  /* ⚠️ ГЛАВНОЕ: у дня без разложения галочки применять нельзя — иначе загрузка обнулится.
     Такой день считается по-старому, по детоместам. */
  ctx._fillStore.fill = { '2026-09-02': { '10': [1, 11, 8, 1, 8, 300] } };   // старый формат
  ctx.S.fillCats = { full: 1, trial: 0, zero: 0, none: 0, miss: 0 };
  const rOld = API._fillRows(API._fillAgg('2026-08-31', '2026-09-06')).find(x => x.gid === '10');
  eq('старый день считается по детоместам', rOld.cnt, rOld.fact);
  eq('и загрузка не обнулилась', Math.round(rOld.pct), 70);
  eq('и это видно по флагу', rOld.split, false);
  check('и наверху сказано, что надо пересчитать',
        API._fillHtml().indexOf('нажмите «Пересчитать заново»') > 0);

  // смешанный период: часть дней с разложением, часть без — тоже откат, чтобы не занизить
  ctx._fillStore.fill = {
    '2026-09-02': { '10': [1, 11, 8, 1, 8, 300, 4, 3, 3, 1, 2] },
    '2026-09-03': { '10': [1, 11, 8, 1, 8, 300] },
  };
  const rMix = API._fillRows(API._fillAgg('2026-08-31', '2026-09-06')).find(x => x.gid === '10');
  eq('смешанный период — тоже по детоместам', rMix.cnt, rMix.fact);
  eq('и флаг честный', rMix.split, false);

  ctx._fillStore.fill = keep; ctx.S.fillCats = keepCats; ctx.S.fillPlan = { '20': 6 };
}


/* ================= «ЧЕЛ/ГР» ИЗ ПЛАНИРОВЩИКА =================
   Вписывать плановую вместимость второй раз по каждой группе незачем: «Планировщик загрузки»
   её уже знает. Курс группы находим через предмет Alfa, сопоставленный в «Публикации групп».
   Загрузка после этого считается от ПЛАНА, а не от того, сколько стульев влезает, — и может
   быть больше 100%, если набрали сверх плана. */
{
  eq('курс группы найден через предмет', API._fillPlanPerGroup('10'), 6);
  eq('у группы без курса в планировщике — ноль', API._fillPlanPerGroup('20'), 0);
  eq('у «вне групп» вместимости нет', API._fillPlanPerGroup('0'), 0);

  // предмет не сопоставлен ни с одним курсом — молча берём 0 и падаем на следующий источник
  const keepMap = ctx._pubMap;
  ctx._pubMap = () => ({ subj: {} });
  eq('без сопоставления — ноль', API._fillPlanPerGroup('10'), 0);
  ctx._pubMap = keepMap;

  // в таблице источник помечен, чтобы было видно, откуда цифра
  const h = API._fillHtml();
  check('источник «планировщик» помечен', h.indexOf('Планировщика загрузки') > 0, h.slice(0, 300));
  check('колонки «Мест» больше нет', h.indexOf('title="Вместимость × занятий">Мест<') < 0);
  check('а «Детомест» осталась', h.indexOf('>Детомест<') > 0);
}


/* ================= ТЕРМИНОЛОГИЯ: ДЕТОМЕСТО = ПЛАНОВОЕ МЕСТО =================
   Поправка владельца 07.09.2026: детоместо — это МЕСТО для ребёнка при планировании группы
   (вместимость × занятий), то есть ЗНАМЕНАТЕЛЬ и потолок загрузки. Не списание и не пришедший.
   Раньше колонка «Детомест» показывала числитель — списания. Эти проверки держат смысл на месте. */
{
  ctx._fillPeriod = 'w:2026-08-31';
  const agg = API._fillAgg('2026-08-31', '2026-09-06');
  const rows = API._fillRows(agg);
  const byId = {}; rows.forEach(r => { byId[r.gid] = r; });

  // группа 10: «Чел/гр» 6 × 2 занятия = 12 плановых детомест, списаний 15
  eq('детоместа — это план (вместимость × занятий)', byId['10'].seats, 12);
  eq('списания — отдельная величина', byId['10'].fact, 15);
  check('и они не равны', byId['10'].seats !== byId['10'].fact);

  const h = API._fillHtml();
  check('колонка «Детомест» подписана как план',
        h.indexOf('Плановые места: вместимость группы × число её занятий') > 0, h.slice(0, 400));
  check('и рядом колонка «Списаний»', h.indexOf('>Списаний<') > 0);
  check('плитка «детоместа (план)»', h.indexOf('детоместа (план)') > 0);
  check('плитка «списаний»', h.indexOf('>списаний<') > 0);
  check('старой подписи «детомест по абонементу» больше нет', h.indexOf('детомест по абонементу') < 0);
  check('и «мест было» тоже', h.indexOf('>мест было<') < 0);

  /* ⚠️ ЛОВУШКА 1. Строка пряталась, когда ноль ЧИСЛИТЕЛЬ. По новой терминологии группа с
     плановыми детоместами и нулём списаний — это пустые места, то есть потерянные деньги,
     и она обязана быть видна. */
  const keep = ctx._fillStore.fill;
  ctx._fillStore.fill = { '2026-09-02': { '10': [1, 0, 0, 0, 0, 0] } };   // занятие было, списаний нет
  const empty = API._fillRows(API._fillAgg('2026-08-31', '2026-09-06')).find(r => r.gid === '10');
  check('группа без списаний не исчезла', !!empty, 'строка пропала');
  eq('плановые детоместа у неё есть', empty.seats, 6);
  eq('а списаний ноль', empty.fact, 0);
  eq('и загрузка честный ноль', Math.round(empty.pct), 0);
  ctx._fillStore.fill = keep;

  /* ⚠️ ЛОВУШКА 2. Итог считался от fact, а строки — от cnt: галочки меняли проценты в строках,
     но не в плитке «загрузка» и не в ИТОГО. Сумма не сходилась со своими же слагаемыми. */
  const keepCats = ctx.S.fillCats;
  ctx.S.fillCats = { full: 1, trial: 0, zero: 0, none: 0, miss: 0 };
  const rows2 = API._fillRows(API._fillAgg('2026-08-31', '2026-09-06'));
  const T2 = API._fillTotals(rows2);
  const sumCnt = rows2.reduce((a, r) => a + (r.cnt || 0), 0);
  eq('итог берёт тот же числитель, что и строки', T2.cnt, sumCnt);
  eq('и процент считается от него', Math.round(T2.pct), Math.round(100 * sumCnt / T2.seats));
  ctx.S.fillCats = keepCats;

  // подпись сортировки больше не врёт: ключ seats сортирует по списаниям
  check('подпись сортировки честная', h.indexOf('Сначала с самым большим числом списаний') > 0);
}

/* ================= ПЕДАГОГ ИЗ ЗАНЯТИЙ =================
   В карточке группы Alfa предмета нет вовсе, а teacher_ids у большинства групп пуст — педагога
   назначают занятию. Поэтому обе колонки берутся из занятий периода, и замены видно. */
{
  const keep = ctx._fillStore.fill;
  ctx._fillStore.fmt = ['les','seats','paid','trial','att','rev','attPaid','attFree','noAttPaid','attTrial','attZero','tch','sbj'];
  ctx._fillStore.fill = {
    '2026-09-01': { '10': [1, 7, 7, 0, 6, 210, 6, 1, 1, 0, 0, { '5': 1 }, { '11': 1 }] },
    '2026-09-03': { '10': [1, 8, 8, 0, 7, 240, 7, 1, 1, 0, 0, { '5': 1 }, { '11': 1 }] },
    '2026-09-05': { '10': [1, 8, 8, 0, 7, 240, 7, 1, 1, 0, 0, { '6': 1 }, { '11': 1 }] },  // замена
  };
  const agg = API._fillAgg('2026-08-31', '2026-09-06');
  const T = API._fillTeach('10', agg);
  eq('главный педагог — кто провёл больше', T.name, 'Бурдук Наталья');   // id 5 — 2 занятия
  eq('замена посчитана', T.more, 1);
  eq('и видны оба', T.all.map(x => x.name + ':' + x.n).join(','), 'Бурдук Наталья:2,Козырев Влад:1');
  eq('предмет тоже из занятий', API._fillSubjName('10', agg), 'Английский язык');

  const h = API._fillHtml();
  check('ФИО педагога в таблице', h.indexOf('Бурдук Наталья') > 0, h.slice(0, 300));
  check('замена помечена', h.indexOf('>+1<') > 0, 'нет пометки замены');

  // старые дни без карт — падаем на карточку группы, а не на пустоту.
  // Берём группу 20: в её карточке педагог 6, и совпадение с картами исключено
  ctx._fillStore.fill = { '2026-09-01': { '20': [1, 5, 4, 1, 4, 120] } };
  const agg2 = API._fillAgg('2026-08-31', '2026-09-06');
  eq('без карт берём педагога из карточки', API._fillTeach('20', agg2).name, 'Козырев Влад');
  eq('и замен там нет', API._fillTeach('20', agg2).more, 0);
  ctx._fillStore.fill = keep;
}


console.log(bad ? '\nПРОВАЛЕНО: ' + bad : '\nВсё сошлось');
process.exit(bad ? 1 : 0);
