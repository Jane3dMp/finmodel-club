// Касса сегодня + приход за месяц («Дашборд администратора → Кассы»).
// Запуск: node backend/test-kassa-month.cjs
//
// Проверяется НАСТОЯЩИЙ код из index.html. Месяц складывается из дневных снимков, которые
// копит cron в 22:00: если снимка за прошедший день нет, сумма неполная — и об этом должно
// быть сказано, иначе Жанна примет заниженный приход за правду.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const NAMES = ['_kassaMonth', '_kassaTopHtml', '_kassaDayWord'];
const ONE_LINERS = ['_kassaDMY'];
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of NAMES)      grab(n, new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
for (const n of ONE_LINERS) grab(n, new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));

/* --- снимки платежей: 1, 2 и 4 сентября собраны, 3-е пропущено (cron не отработал) --- */
const day = (income, byIn, expense, byOut, count, ts) =>
  ({ income, byIn, expense: expense || 0, byOut: byOut || {}, count: count || 0, ts: ts || '2026-09-01T22:00:03+03:00' });
const snap = {
  '2026-09-01': day(1200, { 'ЕРИП': 500, 'Наличные': 400, 'Терминал': 300 }, 50, { 'Наличные': 50 }, 9),
  '2026-09-02': day(1814, { 'ЕРИП': 456, 'Наличные': 862, 'Терминал': 496 }, 92, { 'Наличные': 35, 'Оплата картой': 57 }, 13),
  // 03.09 пропущен
  '2026-09-04': day(980, { 'ЕРИП': 300, 'Наличные': 680 }, 0, {}, 6, '2026-09-04T14:12:40+03:00'),
  '2026-08-31': day(9999, { 'ЕРИП': 9999 }, 0, {}, 1),        // прошлый месяц — не считаем
  '2026-10-01': day(7777, { 'ЕРИП': 7777 }, 0, {}, 1),        // следующий — тоже
};
const ctx = {
  _paySnap: snap, _kassaDate: '2026-09-04', _kassaBusy: false, _admSec: 'kassa',
  _todayIso: () => '2026-09-04',
  _RU_MON: ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'],
  _gm: n => Math.round(+n || 0).toLocaleString('ru-RU'),
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  _kassaSheet: () => ({}),
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_kassaMonth,_kassaTopHtml,_kassaDayWord, setSnap:v=>{_paySnap=v}, setDate:v=>{_kassaDate=v}}; }')(ctx);

/* ================= сумма за месяц ================= */
const M = API._kassaMonth('2026-09');
eq('дней с данными', M.days, 3);
eq('операций за месяц', M.count, 9 + 13 + 6);
eq('ЕРИП за месяц', M.byIn['ЕРИП'], 500 + 456 + 300);
eq('Наличные за месяц', M.byIn['Наличные'], 400 + 862 + 680);
eq('Терминал за месяц', M.byIn['Терминал'], 300 + 496);
eq('итого приход', M.income, 1200 + 1814 + 980);
eq('итого расход', M.expense, 50 + 92);
eq('расход тоже по кассам', M.byOut['Наличные'], 50 + 35);
check('соседние месяцы не попали', !M.byIn['ЕРИП'] || M.byIn['ЕРИП'] < 9999);

/* ================= пропущенные дни ================= */
eq('пропущен один день', M.missing.length, 1);
eq('и это 3 сентября', M.missing[0], '2026-09-03');
check('будущие дни месяца не считаются пропущенными',
      M.missing.every(d => d <= '2026-09-04'), M.missing.join(', '));

/* ================= отрисовка ================= */
const h = API._kassaTopHtml();
check('карточка «Касса сегодня»', h.indexOf('Касса сегодня') > 0);
check('карточка месяца с названием', h.indexOf('Приход за сентябрь 2026') > 0);
check('сегодняшний приход показан', h.indexOf('980') > 0);
check('время последнего обновления видно', h.indexOf('обновлено 14:12') > 0);
check('месяц: сказано, сколько дней собрано', h.indexOf('дней с данными: 3') > 0);
check('месяц: предупреждение про пропуск', h.indexOf('Нет снимка за 1 день') > 0,
      h.slice(Math.max(0, h.indexOf('Нет снимка') - 40), h.indexOf('Нет снимка') + 120));
check('пропущенный день назван по-человечески', h.indexOf('03.09') > 0);
check('про один день — «он не вошёл», а не «эти дни»', h.indexOf('он в сумму не вошёл') > 0);
check('есть кнопка дособрать', h.indexOf('kassaFillMonth()') > 0);
check('есть кнопка обновить сегодня', h.indexOf('kassaToday(true)') > 0);

/* --- все дни на месте: лишнего предупреждения нет --- */
const full = Object.assign({}, snap);
full['2026-09-03'] = day(700, { 'ЕРИП': 700 }, 0, {}, 4);
API.setSnap(full);
eq('пропусков не осталось', API._kassaMonth('2026-09').missing.length, 0);
check('и предупреждения тоже', API._kassaTopHtml().indexOf('Нет снимка') < 0);

/* --- за сегодня снимка ещё нет: карточка не врёт нулём --- */
const noToday = Object.assign({}, full); delete noToday['2026-09-04'];
API.setSnap(noToday);
const h2 = API._kassaTopHtml();
check('без снимка за сегодня — «считаю», а не 0', h2.indexOf('Считаю из Alfa') > 0);
eq('сегодня попало в пропущенные', API._kassaMonth('2026-09').missing.join(''), '2026-09-04');

/* --- месяц берётся от выбранной даты, а не от сегодня --- */
API.setSnap(full);
API.setDate('2026-08-31');
eq('август считается отдельно', API._kassaMonth('2026-08').income, 9999);
check('заголовок карточки — про август', API._kassaTopHtml().indexOf('Приход за август 2026') > 0);
API.setDate('2026-09-04');

/* --- склонение дней --- */
eq('1 день', API._kassaDayWord(1), 'день');
eq('2 дня', API._kassaDayWord(2), 'дня');
eq('5 дней', API._kassaDayWord(5), 'дней');
eq('11 дней', API._kassaDayWord(11), 'дней');

/* --- хранилище ещё не загружено --- */
API.setSnap(null);
check('пока снимки грузятся — так и написано', API._kassaTopHtml().indexOf('Читаю сохранённые снимки') > 0);

console.log(bad ? '\n❌ провалено проверок: ' + bad : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
