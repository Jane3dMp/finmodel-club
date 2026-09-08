// Колонка «Ожидается 🔒» и «%» в таблице «Реализация» (Дашборд администратора).
// Запуск: node backend/test-expect-column.cjs
//
// Проверяется НАСТОЯЩИЙ _realHtml из index.html. Серверная заморозка — отдельно:
// php backend/test-expect-freeze.php
process.env.TZ = 'Europe/Minsk';   // подпись про снимок печатается в поясе браузера
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const m = html.match(/\nfunction _realHtml\([^)]*\)\s*\{[\s\S]*?\n\}/m);
// _realHtml подписывает, когда снимался недельный снимок. Берём НАСТОЯЩУЮ _realSnapNote,
// а не заглушку: подпись под кнопками — то, по чему видно, отработал ли воскресный cron.
const iSnap = html.indexOf('function _realSnapNote(');
const eSnap = html.indexOf(String.fromCharCode(10) + '}', iSnap);
if (iSnap < 0 || eSnap < 0) { console.log('не найдено в index.html: _realSnapNote'); process.exit(1); }
const snapSrc = html.slice(iSnap, eSnap + 2)
  // время снимка печатается в поясе браузера — помощник берём оттуда же, настоящий
  + (function () {
      const i = html.indexOf("function _tsDMYHM(");
      const e = html.indexOf(String.fromCharCode(10) + "}", i);
      if (i < 0 || e < 0) { console.log("не найдено в index.html: _tsDMYHM"); process.exit(1); }
      return String.fromCharCode(10) + html.slice(i, e + 2);
    })();
if (!m) { console.log('не найдено в index.html: _realHtml'); process.exit(1); }

/* --- сентябрь 2026, сегодня 10-е ---
   1–6   прошли, но неделю никто не фиксировал → сравнивать не с чем
   7–10  прошли, ожидание заморожено в вс 06.09 → есть %
   11–13 впереди, заморожено                    → % ещё нет
   14–16 впереди, НЕ заморожено                 → живая цифра без замка */
/* Подпись про снимок берёт неделю от реальных часов (nextMon), поэтому ключ считаем так же,
   иначе тест начнёт падать в другой день. */
const _d = new Date(); const _w = _d.getDay() === 0 ? 7 : _d.getDay();
_d.setDate(_d.getDate() - (_w - 1) + 7);
const NEXT_MON = _d.getFullYear() + '-' + String(_d.getMonth() + 1).padStart(2, '0') + '-' + String(_d.getDate()).padStart(2, '0');
const store = {};
const put = (d, o) => { store['2026-09-' + String(d).padStart(2, '0')] = Object.assign(
  { present: 0, all: 0, planned: 0, lessons: 0, plannedLessons: 0 }, o); };
const ts = '2026-09-06T22:00:11+03:00';
put(2, { present: 1783, all: 1805, lessons: 12 });
put(3, { present: 1658, all: 1697, lessons: 12 });
put(7,  { present: 2980, all: 3120, lessons: 19, expect: 3243, expectTs: ts });
put(8,  { present: 2020, all: 2180, lessons: 14, expect: 2377, expectTs: ts });
put(9,  { present: 2210, all: 2290, lessons: 15, expect: 2360, expectTs: ts });
put(10, { present: 1720, all: 1810, lessons: 13, expect: 2068, expectTs: ts });
put(11, { expect: 2283, expectTs: ts, planned: 2283, plannedLessons: 11 });
put(12, { expect: 8758, expectTs: ts, planned: 8758, plannedLessons: 36 });
put(14, { planned: 3243, plannedLessons: 19 });

const ctx = {
  _realMonth: '2026-09', _realStore: store, _realBusy: false,
  /* Окно «от даты до даты» в «Реализации»: пусто = поведение по умолчанию («дату подскажи
     сам», «считай всю неделю пн–вс»). Без этих двух полей _realHtml не собирается вовсе. */
  _realFrom: '', _realTo: '',
  _RU_MON: ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'],
  _todayIso: () => '2026-09-10',
  _dIso: d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'),
  _gm: n => Math.round(+n || 0).toLocaleString('ru-RU'),
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  _realBranchLabel: () => 'Пожарный, 19',
  location: { origin: 'https://app.proznanie.club' },
  _weekPlans: { [NEXT_MON]: { ts, expectFrozen: 7 } },
  _realChanges: [],
};
// журнал правок задним числом рисуется той же функцией — берём НАСТОЯЩИЕ помощники.
// _realChgLabel и _kassaDMY однострочные, остальные многострочные: пробуем оба вида и берём
// тот, что короче, иначе однострочный якорь утащил бы за собой следующую функцию целиком.
const chgSrc = ['_realChgFor', '_realChgLabel', '_realChgBadge', '_realChgHtml', '_kassaDMY'].map(n => {
  const one = html.match(new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));
  const many = html.match(new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  const a = one ? one[0] : null, b = many ? many[0] : null;
  const got = (a && (!b || a.length < b.length)) ? a : b;
  if (!got) { console.log('не найдено в index.html: ' + n); process.exit(1); }
  return got;
}).join(String.fromCharCode(10));
const API = new Function('ctx', 'with (ctx) { ' + m[0] + ';' + snapSrc + ';' + chgSrc +
  ' return {_realHtml, _realSnapNote, _realChgHtml}; }')(ctx);
const h = API._realHtml();

/* --- разбор строк таблицы без DOM: колонки по порядку --- */
const body = h.slice(h.indexOf('<tbody>'), h.indexOf('</tbody>'));
const trs = body.split('<tr').slice(1);
const cells = tr => (tr.match(/<td[^>]*>([\s\S]*?)<\/td>/g) || [])
  .map(td => td.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim());
const day = n => cells(trs[n - 1]);        // строки идут по дням месяца

eq('колонка «Ожидается» на месте', h.indexOf('>Ожидается<') > 0, true);

/* --- выходные заливкой: в колонке «Дн» одни числа, день недели по ним не прочитать --- */
// сентябрь 2026 начинается со вторника, значит суббота-воскресенье: 5-6, 12-13, 19-20, 26-27
const wknd = n => /class="wknd"/.test(trs[n - 1]);
check('суббота 5-го помечена', wknd(5));
check('воскресенье 6-го помечено', wknd(6));
check('и следующие выходные тоже', wknd(12) && wknd(13) && wknd(19) && wknd(20) && wknd(26) && wknd(27));
check('будни не помечены', ![1,2,3,4,7,8,9,10,11,14,15,16,17,18,21,22,23,24,25,28,29,30].some(wknd));
eq('всего выходных за месяц', (body.match(/class="wknd"/g) || []).length, 8);
check('в подсказке дня назван день недели', /title="суббота"/.test(trs[4]) && /title="воскресенье"/.test(trs[5]), trs[4].slice(0, 90));
// заливка бьёт зебру только через id таблицы — без него правило проигрывало бы по весу
check('у таблицы есть id для правила заливки', h.indexOf('<table id="realTable"') > 0);
check('будущие дни по-прежнему приглушены', /class="wknd" style="opacity:.6"/.test(body), 'класс и приглушение должны уживаться');
eq('колонка «%» добавлена', h.indexOf('>%<') > 0, true);

/* --- прошедший день с замороженным ожиданием --- */
const d7 = day(7);
eq('7-е: без пропусков', d7[1], '2 980');
eq('7-е: ожидание заморожено', d7[3], '3 243 🔒');
eq('7-е: процент выполнения', d7[4], '94%');   // (2980+3120)/2 = 3050 из 3243
check('7-е: в подсказке дата заморозки', trs[6].indexOf('2026-09-06 22:00') > 0);

/* --- ГЛАВНОЕ: ежедневный пересчёт обнулил planned, но ожидание осталось --- */
check('ожидание показано, хотя planned у прошедшего дня = 0',
      store['2026-09-07'].planned === 0 && d7[3].indexOf('3 243') === 0);

/* --- будущий день: заморожено, но процента ещё нет --- */
const d11 = day(11);
eq('11-е: ожидание с замком', d11[3], '2 283 🔒');
eq('11-е: процента нет — день не наступил', d11[4], '');

/* --- будущий день без заморозки: живая цифра, без замка --- */
const d14 = day(14);
eq('14-е: живой прогноз показан', d14[3], '3 243');
check('14-е: без замка — неделю не фиксировали', d14[3].indexOf('🔒') < 0);
check('14-е: помечено как живое', trs[13].indexOf('будет меняться') > 0);

/* --- прошедший день, который никто не фиксировал --- */
eq('2-е: ожидания нет — историю не восстановить', day(2)[3], '');
eq('2-е: и процента нет', day(2)[4], '');

/* --- итог месяца: процент только по прошедшим дням =================
   Была ошибка: факт первой недели делился на ожидание всего месяца вперёд (включая 11-е и
   12-е) и выполнение показывало 43% вместо 91%. */
const tot = cells(trs[trs.length - 1]);
eq('итог: ожидание за месяц', tot[3], '21 089');          // все замороженные дни
eq('итог: процент по прошедшим дням', tot[4], '91%');     // 9 165 из 10 048
// toLocaleString('ru-RU') разделяет тысячи неразрывным пробелом — сравниваем по обычному
const plain = s => String(s).replace(/ /g, ' ');
check('итог: в подсказке видно, из чего процент',
      plain(trs[trs.length - 1]).indexOf('По прошедшим дням: факт 9 165 из ожидавшихся 10 048') > 0,
      plain(trs[trs.length - 1]).slice(0, 200));

/* --- «Накопительно» = нарастающее СРЕДНЕЕ, а не только пришедшие =================
   Раньше копились только «без пропусков», и накопительное расходилось с выручкой месяца
   во «Все показатели» / «Прогноз по всем» / отчёте продаж. */
eq('2-е: накопительно = (1783+1805)/2', day(2)[5], '1 794');
eq('3-е: плюс (1658+1697)/2', day(3)[5], '3 472');           // 1794 + 1677,5
eq('итог месяца = среднее без/с пропусками', tot[5], '12 637');  // (12 371 + 12 902) / 2
check('накопительное сходится с последним днём с занятиями',
      day(10)[5] === tot[5], day(10)[5] + ' vs ' + tot[5]);
check('в шапке сказано, что это среднее', h.indexOf('среднее «без пропусков» и «с пропусками»') > 0);

/* --- кнопка ручной фиксации --- */
check('есть кнопка «Зафиксировать ожидание»', h.indexOf('realFreezeExpect()') > 0);
check('поле недели по умолчанию — предстоящая',
      h.indexOf('value="2026-09-') > 0 || h.indexOf('id="realWeekDate"') > 0);

/* --- месяц без единой заморозки: сказано, что сравнивать не с чем --- */
ctx._realStore = { '2026-09-02': { present: 1783, all: 1805, planned: 0, lessons: 12 } };
const h0 = API._realHtml();
check('без заморозок — понятное объяснение, а не пустая колонка',
      h0.indexOf('ожидание не зафиксировано') > 0);


/* Подпись про недельный снимок: по ней видно, отработал ли воскресный cron_weekplan.php.
   Расписание задач живёт в панели хостинга, из приложения его не проверить. */
check('снимок недели показан в подписи', h.includes('Снимок недели с ' + NEXT_MON), h.slice(h.indexOf('Снимок'), h.indexOf('Снимок') + 90));
check('видно, сколько дней зафиксировано', h.includes('дней зафиксировано: 7'));
check('свежий снимок не помечается тревогой', !API._realSnapNote(NEXT_MON).includes('не отрабатывает'));
check('снимка нет — сказано прямо и с подсказкой',
    API._realSnapNote('2099-01-04').includes('ещё не было') && API._realSnapNote('2099-01-04').includes('не заведена'));

/* ================= правки задним числом =================
   Само исправление — не ошибка: посещаемость правят, платёж переносят. Важно, что цифра,
   которую уже отправили в отдел продаж, стала другой, а раньше об этом никто не узнавал. */
{
  // _gm разделяет тысячи НЕразрывным пробелом: без приведения поиск по строке не находит ничего
  const NBSP = String.fromCharCode(160);
  const plain = x => String(x).split(NBSP).join(String.fromCharCode(32));
  check('без правок — так и написано', API._realChgHtml().indexOf('Правок задним числом не замечено') > 0);

  ctx._realChanges = [
    { d: '2026-09-03', f: 'fact', was: 1658, now: 1590, at: '2026-09-06T22:00:10+03:00', wasLes: 12, nowLes: 12 },
    { d: '2026-09-04', f: 'payIn', was: 4052, now: 4300, at: '2026-09-06T22:00:12+03:00' },
    // по одному дню и виду показываем ПОСЛЕДНЮЮ правку: промежуточные состояния только шумят
    { d: '2026-09-03', f: 'fact', was: 1590, now: 1602, at: '2026-09-07T22:00:09+03:00', wasLes: 12, nowLes: 12 },
  ];
  const hc = API._realChgHtml();
  check('заголовок с числом правок', hc.indexOf('Правки задним числом (3)') > 0, hc.slice(0, 160));
  check('видно, что было', plain(hc).indexOf('1 658') > 0, hc);
  check('и что стало', plain(hc).indexOf('1 602') > 0, hc);
  check('касса тоже попала', hc.indexOf('приход кассы') > 0, hc);
  check('время замечено местное', hc.indexOf('06.09 22:00') > 0, hc);
  check('свежие правки сверху', hc.indexOf('07.09 22:00') < hc.indexOf('06.09 22:00'), hc);

  const h2 = API._realHtml();
  const body2 = h2.slice(h2.indexOf('<tbody>'), h2.indexOf('</tbody>'));
  const trs2 = body2.split('<tr').slice(1);
  check('3-е число помечено значком', trs2[2].indexOf('✎') > 0, trs2[2]);
  check('4-е тоже', trs2[3].indexOf('✎') > 0, trs2[3]);
  check('а нетронутое 5-е — нет', trs2[4].indexOf('✎') < 0, trs2[4]);
  check('в подсказке дня — последнее значение, а не промежуточное',
        plain(trs2[2]).indexOf('стало 1 602') > 0, trs2[2]);
  check('и оба вида правок по разным дням не смешались',
        trs2[3].indexOf('приход кассы') > 0 && trs2[2].indexOf('приход кассы') < 0, trs2[3]);
  ctx._realChanges = [];
}


console.log(bad ? '\n❌ провалено проверок: ' + bad : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
