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
const NAMES = ['_kassaMonth', '_kassaTopHtml', '_kassaDayWord',
               '_kassaMerge', '_kassaNetTable', '_kassaAccNames', '_kassaCashAcc', '_kassaCash', '_kassaCashCard'];
const ONE_LINERS = ['_kassaDMY', '_kassaOpen'];
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
  S: { kassaOpen: {} }, persistLocal: () => {}, _kassaTodayBusy: false, _kassaProbeLast: '',
  _dIso: d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'),
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_kassaMonth,_kassaTopHtml,_kassaDayWord,_kassaMerge,_kassaNetTable,_kassaCashAcc,_kassaCash,_kassaCashCard,' +
  ' setSnap:v=>{_paySnap=v}, setDate:v=>{_kassaDate=v}}; }')(ctx);

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
check('карточка месяца с названием', h.indexOf('Касса за сентябрь 2026') > 0);
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
check('заголовок карточки — про август', API._kassaTopHtml().indexOf('Касса за август 2026') > 0);
API.setDate('2026-09-04');

/* --- склонение дней --- */
eq('1 день', API._kassaDayWord(1), 'день');
eq('2 дня', API._kassaDayWord(2), 'дня');
eq('5 дней', API._kassaDayWord(5), 'дней');
eq('11 дней', API._kassaDayWord(11), 'дней');

/* ================= приход / расход / остаток по кассам =================
   Касса у прихода и расхода — одна и та же запись Alfa, поэтому «Наличные» сопоставляются
   по имени. Но идём по ОБЪЕДИНЕНИЮ ключей: «Оплата картой» есть только в расходе. */
API.setSnap(snap);
const rows = API._kassaMerge(snap['2026-09-02'].byIn, snap['2026-09-02'].byOut);
eq('строк — объединение касс прихода и расхода', rows.length, 4);      // ЕРИП, Наличные, Оплата картой, Терминал
const nal = rows.find(r => r.k === 'Наличные');
eq('наличные: приход', nal.in, 862);
eq('наличные: расход', nal.out, 35);
eq('наличные: остаток = приход − расход', nal.net, 862 - 35);
const card = rows.find(r => r.k === 'Оплата картой');
check('касса только с расходом не потерялась', !!card);
eq('у неё нет прихода', card.in, 0);
eq('и остаток отрицательный', card.net, -57);
eq('ЕРИП без расхода — остаток равен приходу', rows.find(r => r.k === 'ЕРИП').net, 456);

const t = API._kassaNetTable(snap['2026-09-02'].byIn, snap['2026-09-02'].byOut);
// «Остаток» для ЕРИП/терминала читался бы как баланс счёта — колонка называется честно
check('таблица с четырьмя колонками', t.indexOf('>Приход<') > 0 && t.indexOf('>Расход<') > 0 && t.indexOf('>Приход − расход<') > 0);
check('слово «Остаток» оставлено только наличным', t.indexOf('>Остаток<') < 0);
// toLocaleString('ru-RU') разделяет тысячи неразрывным пробелом
check('итог остатка = 1814 − 92', t.replace(/ /g, ' ').indexOf('1 722') > 0, t.slice(t.lastIndexOf('<tr'), t.length));
check('расход показан со знаком минус', t.indexOf('−35') > 0);
check('пусто — понятная надпись', API._kassaNetTable({}, {}, { empty: 'операций нет' }).indexOf('операций нет') > 0);

const hm = API._kassaTopHtml();
check('карточка месяца переименована — теперь там и расход', hm.indexOf('Касса за сентябрь 2026') > 0);
check('в карточке месяца видна касса с одним расходом', hm.indexOf('Оплата картой') > 0);
check('в карточках — колонка приход − расход', hm.split('Приход − расход').length > 2);

// пока Alfa досчитывает сегодня, день не считается пропущенным ни в месяце, ни в наличных
API.setSnap(noToday);
ctx._kassaTodayBusy = true;
check('сегодня «в пути» — не пропуск в месяце', API._kassaMonth('2026-09').missing.indexOf('2026-09-04') < 0);
ctx.S.kassaOpen = { date: '2026-09-01', sum: 0 };
check('сегодня «в пути» — не пропуск в наличных', API._kassaCash().missing.indexOf('2026-09-04') < 0);
ctx._kassaTodayBusy = false;
check('а когда ответ пришёл и снимка нет — пропуск', API._kassaCash().missing.indexOf('2026-09-04') >= 0);
ctx.S.kassaOpen = {};
API.setSnap(snap);

/* ================= наличные в клубе =================
   Якорь — ручной пересчёт на конец дня X; дальше приход − расход наличными по снимкам. */
eq('касса наличных угадана по названию', API._kassaCashAcc(), 'Наличные');
let C = API._kassaCash();
check('без якоря — не считаем', !C.set && C.now === 0);
check('без якоря карточка просит пересчитать', API._kassaCashCard().indexOf('Пересчитайте наличные') > 0);

// ⚠️ «Безналичные» тоже содержат «нал» и по алфавиту идут раньше — угадывать надо точнее
API.setSnap({ '2026-09-02': day(10, { 'Безналичные': 5, 'Наличные': 5 }, 0, {}, 2) });
eq('«Безналичные» не приняты за наличные', API._kassaCashAcc(), 'Наличные');
API.setSnap({ '2026-09-02': day(10, { 'Безналичный расчёт': 5, 'Наличка': 5 }, 0, {}, 2) });
eq('«Наличка» подходит, «Безналичный» — нет', API._kassaCashAcc(), 'Наличка');
API.setSnap({ '2026-09-02': day(10, { 'ЕРИП': 5, 'Терминал': 5 }, 0, {}, 2) });
eq('нет похожей кассы — не подставляем первую попавшуюся', API._kassaCashAcc(), '');
ctx.S.kassaOpen = { date: '2026-09-01', sum: 100 };
check('без кассы якорь не считается, карточка просит выбрать',
      !API._kassaCash().set && API._kassaCashCard().indexOf('выберите, какая касса') > 0);
ctx.S.kassaOpen = {};
API.setSnap(snap);

// сохранённая касса всегда в списке — иначе селект показал бы одно, а считалось бы другое
ctx.S.kassaOpen = { acc: 'Касса-старое-имя', date: '2026-09-01', sum: 10 };
check('переименованная касса остаётся в селекте выбранной',
      API._kassaCashCard().indexOf('value="Касса-старое-имя" selected') > 0);
ctx.S.kassaOpen = {};

// дата пересчёта в будущем — не показываем уверенную цифру
ctx.S.kassaOpen = { date: '2026-09-10', sum: 700 };
check('будущая дата — предупреждение вместо остатка',
      API._kassaCash().future && API._kassaCashCard().indexOf('ещё не наступила') > 0);
ctx.S.kassaOpen = {};

// пустая сумма = якоря нет (0 в кассе и «не вписано» — разные вещи)
ctx.S.kassaOpen = { date: '2026-09-01' };
check('дата без суммы — якорь не задан', !API._kassaCash().set);
ctx.S.kassaOpen = { date: '2026-09-01', sum: 0 };
check('а вписанный ноль — задан', API._kassaCash().set && API._kassaCash().now === 862 + 680 - 35);
ctx.S.kassaOpen = {};

ctx.S.kassaOpen = { date: '2026-09-01', sum: 1000 };            // вечером 1-го в кассе было 1000
C = API._kassaCash();
eq('дни считаются строго ПОСЛЕ даты пересчёта', C.days, 2);       // 02.09 и 04.09 (03.09 нет)
eq('приход наличными после якоря', C.inc, 862 + 680);
eq('расход наличными после якоря', C.out, 35);
eq('сейчас в кассе', C.now, 1000 + 862 + 680 - 35);
eq('день без снимка после якоря помечен', C.missing.join(''), '2026-09-03');
check('в карточке — итог и предупреждение', (() => { const h = API._kassaCashCard(); return h.indexOf('сейчас в кассе') > 0 && h.indexOf('остаток неточный') > 0; })());
check('день 1-го (сам якорь) в приход не попал', C.inc !== 862 + 680 + 400);
check('в карточке сказано «на конец» дня', API._kassaCashCard().indexOf('остаток на конец 01.09') > 0);
// расходы по другим кассам за те же дни не вычитаются из наличных, но и не прячутся
eq('расход картой после якоря учтён отдельно', C.other['Оплата картой'], 57);
check('и показан в карточке как не вычтенный', API._kassaCashCard().indexOf('не вычтено, другие кассы: Оплата картой −57') > 0);
check('наличный расход в «другие» не попал', C.other['Наличные'] === undefined);

// пересчёт сегодня днём — подсказка про «конец дня»
ctx.S.kassaOpen = { date: '2026-09-04', sum: 500 };
check('якорь сегодня — предупреждение про конец дня', API._kassaCashCard().indexOf('считается остаток на <b>конец</b> дня') > 0);

ctx.S.kassaOpen = { date: '2026-09-04', sum: 500 };              // пересчитали сегодня вечером
C = API._kassaCash();
eq('якорь сегодня — дней после него нет', C.days, 0);
eq('остаток = вписанному', C.now, 500);
check('пропусков нет', C.missing.length === 0);

ctx.S.kassaOpen = { acc: 'Терминал', date: '2026-09-01', sum: 0 };   // выбрали другую кассу
eq('можно выбрать другую кассу', API._kassaCash().inc, 496);
ctx.S.kassaOpen = {};

/* --- хранилище ещё не загружено --- */
API.setSnap(null);
check('пока снимки грузятся — так и написано', API._kassaTopHtml().indexOf('Читаю сохранённые снимки') > 0);

console.log(bad ? '\n❌ провалено проверок: ' + bad : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
