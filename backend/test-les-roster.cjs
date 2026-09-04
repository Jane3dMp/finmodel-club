// «Состав из Альфы» в карточке занятия: сопоставление членств Alfa с детьми карточки.
// Запуск: node backend/test-les-roster.cjs
//
// Проверяется НАСТОЯЩИЙ код из index.html, включая настоящие нормализаторы имён
// (_normName, _nameWords, _sameKid, _foldLat) — свой нормализатор здесь уже был и расходился
// с приложением. Главное: ребёнок не должен ни задвоиться, ни потеряться — по alfaId матчим
// первым проходом, по имени — вторым, тёзок с разными отчествами различаем.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const NAMES = ['cleanKidName', '_nameWords', '_sameKid', '_normName', '_rosterActive', '_lesRosterCompare', '_lesRosterHtml'];
const ONE_LINERS = ['_foldLat', '_rosterNameKey'];
const CONSTS = ['_CONFUSE', '_nwCache'];
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of CONSTS)     grab(n, new RegExp('\\nconst ' + n + '=.*\\n', 'm'));
for (const n of NAMES)      grab(n, new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
for (const n of ONE_LINERS) grab(n, new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));
const ctx = {
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  _pubDMY: s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? (m[3] + '.' + m[2] + '.' + m[1]) : String(s || ''); },
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_rosterNameKey,_rosterActive,_lesRosterCompare,_lesRosterHtml}; }')(ctx);

/* ================= ключ имени = канонический _normName ================= */
eq('фамилия + имя, порядок не важен', API._rosterNameKey('Иванова Анна'), API._rosterNameKey('Анна Иванова'));
eq('отчество отбрасывается', API._rosterNameKey('Иванова Анна Сергеевна'), API._rosterNameKey('Иванова Анна'));
eq('мусор из выгрузки «#34,00» срезается', API._rosterNameKey('Богданович Степан #34,00'), API._rosterNameKey('Богданович Степан'));
eq('ё = е', API._rosterNameKey('Ковалёва Алиса'), API._rosterNameKey('Ковалева Алиса'));
eq('латиница-двойник (a, o, c) сворачивается', API._rosterNameKey('Ивaновa Аннa'), API._rosterNameKey('Иванова Анна'));
eq('«(резерв)» не мешает', API._rosterNameKey('Иванова (резерв) Анна'), API._rosterNameKey('Иванова Анна'));
eq('пусто', API._rosterNameKey(''), '');

/* ================= действующее членство ================= */
const T = '2026-09-04';
check('без даты окончания — действует', API._rosterActive({ b_date: '2026-09-02', e_date: null }, T));
check('окончание в будущем — действует', API._rosterActive({ b_date: '2026-09-02', e_date: '2027-05-31' }, T));
check('окончание сегодня — ещё действует', API._rosterActive({ b_date: '2026-09-02', e_date: '2026-09-04' }, T));
check('окончание прошло — выбыл', !API._rosterActive({ b_date: '2025-09-01', e_date: '2026-05-31' }, T));
check('прошлогоднее, но начало в будущем — действует (новый год)', API._rosterActive({ b_date: '2026-09-07', e_date: '2026-05-31' }, T));
check('дата с временем режется до дня', !API._rosterActive({ b_date: '2025-09-01', e_date: '2026-05-31 00:00:00' }, T));

/* ================= сопоставление ================= */
const members = [
  { customer_id: 11, name: 'Иванова Анна Сергеевна', phone: '+375291112233', b_date: '2026-09-02' },
  { customer_id: 12, name: 'Петров Борис', phone: '', b_date: '2026-09-02' },
  { customer_id: 13, name: 'Сидорова Вера', phone: '+375293334455', b_date: '2026-09-15' },
  { customer_id: 14, name: 'Новиков Глеб', phone: '', b_date: '2026-09-02' },        // в карточке нет
  { customer_id: 17, name: 'Титов Лев', phone: '', b_date: null },                   // ❌ в карточке, но зачислен
  { customer_id: 18, name: '', phone: '', b_date: '2026-09-02' },                    // нет в справочнике
];
const kids = [
  { n: 'Иванова Анна', alfaId: 11 },                       // связь есть → по id
  { n: 'Петров Борис', alfaId: null },                     // связи нет → по имени
  { n: 'Сидорова Вера', alfaId: 99 },                      // чужой id, имя совпадает → по имени, с пометкой
  { n: 'Кузнецов Гоша', alfaId: 15, no: true },            // у нас есть, в Alfa нет, «не придёт»
  { n: 'Орлова Даша', alfaId: null },                      // у нас есть, связи нет
  { n: 'Морозов Илья', alfaId: 16 },                       // связь есть, а в группу Alfa не внесён
  { n: 'Титов Лев', alfaId: 17, no: true },                // ❌ у нас, зачислен в Alfa
  { n: 'Иванова Анна', alfaId: 11 },                       // дубль строки с тем же alfaId
  { n: '' },                                               // пустая строка карточки
];
const R = API._lesRosterCompare(members, kids);
eq('и там, и там', R.both.length, 4);
eq('только в Alfa', R.onlyAlfa.map(m => m.customer_id).join(','), '14,18');
eq('только в карточке', R.onlyOurs.map(o => o.k.n).join(', '), 'Кузнецов Гоша, Орлова Даша, Морозов Илья, Иванова Анна');
eq('Анна — по id, хоть имя в Alfa с отчеством', R.both.find(x => x.m.customer_id === 11).how, 'id');
eq('Борис — по имени', R.both.find(x => x.m.customer_id === 12).how, 'name');
eq('Вера — по имени, несмотря на чужой alfaId', R.both.find(x => x.m.customer_id === 13).how, 'name');
check('дубль строки помечен', R.onlyOurs.find(o => o.k.n === 'Иванова Анна').dup === true);
check('обычные «только у нас» не помечены дублем', R.onlyOurs.filter(o => o.dup).length === 1);
check('пустая строка карточки не считается ребёнком', !R.onlyOurs.some(o => o.k.n === ''));
check('каждый член Alfa ровно в одной корзине', R.both.length + R.onlyAlfa.length === members.length);
check('каждый ребёнок карточки ровно в одной корзине', R.both.length + R.onlyOurs.length === kids.filter(k => k.n).length);

/* --- две карточки одного ребёнка в Alfa: связь по id важнее порядка --- */
const dupCards = API._lesRosterCompare(
  [{ customer_id: 21, name: 'Иванова Анна' }, { customer_id: 22, name: 'Иванова Анна' }],
  [{ n: 'Иванова Анна', alfaId: 22 }]);
eq('ребёнок ушёл к СВОЕМУ клиенту (22), а не к первому по порядку', dupCards.both[0].m.customer_id, 22);
eq('и это по id', dupCards.both[0].how, 'id');
eq('второй клиент — «только в Alfa»', dupCards.onlyAlfa.map(m => m.customer_id).join(''), '21');

/* --- тёзки с разными отчествами не путаются --- */
const twins = API._lesRosterCompare(
  [{ customer_id: 2, name: 'Иванова Анна Петровна' }, { customer_id: 1, name: 'Иванова Анна Сергеевна' }],
  [{ n: 'Иванова Анна Сергеевна', alfaId: null }, { n: 'Иванова Анна Петровна', alfaId: null }]);
eq('обе пары сопоставлены', twins.both.length, 2);
eq('Петровна — к Петровне', twins.both.find(x => x.m.customer_id === 2).k.n, 'Иванова Анна Петровна');
eq('Сергеевна — к Сергеевне', twins.both.find(x => x.m.customer_id === 1).k.n, 'Иванова Анна Сергеевна');

/* --- тёзки без отчества в карточке: второй член Alfa не «съедает» того же ребёнка --- */
const same = API._lesRosterCompare(
  [{ customer_id: 21, name: 'Иванова Анна' }, { customer_id: 22, name: 'Иванова Анна' }],
  [{ n: 'Иванова Анна', alfaId: null }]);
eq('первый сопоставлен', same.both.length, 1);
eq('второй остался «только в Alfa»', same.onlyAlfa.length, 1);

/* --- «Имя Фамилия» в Alfa (заведён из amo именем вперёд) --- */
eq('порядок слов не мешает', API._lesRosterCompare([{ customer_id: 3, name: 'Анна Иванова' }], [{ n: 'Иванова Анна' }]).both.length, 1);

/* --- пустые входы --- */
eq('в Alfa никого — все дети «только в карточке»', API._lesRosterCompare([], kids).onlyOurs.length, 8);
eq('в карточке никого — все «только в Alfa»', API._lesRosterCompare(members, []).onlyAlfa.length, 6);
check('undefined вместо массивов не роняет', (() => { try { API._lesRosterCompare(undefined, undefined); return true; } catch (e) { return false; } })());

/* ================= отрисовка ================= */
const h = API._lesRosterHtml(R, { id: 555, name: 'Roblox №1' }, { ts: '2026-09-04T16:41:20+03:00', gone: 2 });
check('заголовок с названием группы', h.indexOf('«Roblox №1»') > 0);
check('число зачисленных = both + onlyAlfa', h.indexOf('зачислено 6') > 0);
check('выбывшие показаны отдельно и объяснены', h.indexOf('выбыли: 2') > 0 && h.indexOf('«Выбыли» — членства с уже прошедшей датой окончания') > 0);
check('время чтения', h.indexOf('прочитано в 16:41') > 0);
check('три секции со счётчиками', h.indexOf('И в Alfa, и в карточке — 4') > 0 && h.indexOf('Только в Alfa — 2') > 0 && h.indexOf('Только в карточке — 4') > 0);
check('пометка «по имени» у сопоставленного без связи (Борис)', (h.match(/>по имени</g) || []).length === 1);
check('чужой alfaId у Веры назван прямо', h.indexOf('связь указывает на другого клиента Alfa (id 99)') > 0);
check('❌ в карточке, но зачислен — видно в зелёной секции', h.indexOf('❌ в карточке «не придёт», а в Alfa зачислен') > 0);
check('нет в справочнике — сказано почему', h.indexOf('Клиент id 18 — нет в справочнике') > 0);
check('дата зачисления по-человечески', h.indexOf('15.09.2026') > 0);
check('пустая дата зачисления — прочерк', h.indexOf('>—<') > 0);
check('дубль строки помечен, а не зовёт нажать 👥', h.indexOf('дубль строки') > 0);
check('подсказка внести через 👥 у ребёнка со связью, но не в группе', h.indexOf('не внесён в группу Alfa') > 0);
check('подсказка ⟳ у ребёнка без связи', h.indexOf('нет связи с Альфой') > 0);
check('в «только в карточке» нет колонки «Зачислен с»', h.split('Зачислен с').length === 3);   // только в первых двух таблицах
check('явно сказано, что только чтение', h.indexOf('Только чтение') > 0);
check('есть кнопки перечитать и скрыть', h.indexOf('lesAlfaRoster(true)') > 0 && h.indexOf('lesAlfaRosterClose()') > 0);
check('нет undefined в разметке', h.indexOf('undefined') < 0);
check('баланс <table>', (h.match(/<table/g) || []).length === (h.match(/<\/table>/g) || []).length);

// имена разошлись при связи по id — видно, кто в карточке
const stale = API._lesRosterCompare([{ customer_id: 13, name: 'Сидорова Вера' }], [{ n: 'Петрова Маша', alfaId: 13 }]);
check('при разных именах показано «в карточке: …»', API._lesRosterHtml(stale, { id: 1 }, null).indexOf('в карточке: Петрова Маша') > 0);

/* --- Alfa не отдала карточки детей: имена и телефоны берём из карточки занятия ---
   Так было у Жанны на «Нейромалыш №1»: четыре «Клиент id 5171 — нет в справочнике» при том,
   что все четверо сопоставлены по alfaId и их имена в карточке есть. */
const noDirR = API._lesRosterCompare(
  [{ customer_id: 5171, name: '', phone: '', b_date: '2026-09-02' }, { customer_id: 9, name: '', phone: '' }],
  [{ n: 'Таборко Варвара', alfaId: 5171, phone: '+375(29)111-11-11' }]);
const hnd = API._lesRosterHtml(noDirR, { id: 5 }, { noDir: true });
check('без справочника — предупреждение в шапке', hnd.indexOf('Alfa не отдала карточки детей') > 0);
check('сопоставленный по id показан именем из карточки', hnd.indexOf('Таборко Варвара') > 0);
check('и телефоном из карточки', hnd.indexOf('+375(29)111-11-11') > 0);
check('«нет в справочнике» не пишется, когда справочника не было', hnd.indexOf('нет в справочнике') < 0);
check('несопоставленный без имени — просто «Клиент id»', hnd.indexOf('Клиент id 9') > 0);
// а если справочник прочитался и клиента в нём нет — это уже сигнал
const hdir = API._lesRosterHtml(noDirR, { id: 5 }, { noDir: false });
check('со справочником пустое имя = «нет в справочнике»', hdir.indexOf('Клиент id 9 — нет в справочнике') > 0);
check('но сопоставленный по id всё равно назван по карточке', hdir.indexOf('Таборко Варвара') > 0);
const h0 = API._lesRosterHtml(API._lesRosterCompare([], []), { id: 5 }, null);
check('без названия группы — «группа id»', h0.indexOf('группа id 5') > 0);
check('пустые секции — понятные подписи', h0.indexOf('никого') > 0);

/* ================= кнопка в карточке ================= */
check('кнопка «Состав из Альфы» есть в карточке занятия', html.indexOf('id="lesAlfaRosterBtn"') > 0 && html.indexOf('onclick="lesAlfaRoster()"') > 0);
check('контейнер для результата есть', html.indexOf('id="lesAlfaRoster"') > 0);
check('title кнопки экранирован', html.indexOf('title="${esc((function(){ const gm=(S.groupMeta||{})[_grpKeyOf(l)]') > 0);
// состав читается тем же путём, что и печать составов: groupMembers слал group_id в теле и
// листал до 20 страниц — шлюз хостинга рвал соединение («Failed to fetch»)
const fn = html.slice(html.indexOf('async function lesAlfaRoster('), html.indexOf('async function lesAlfaRoster(') + 4000);
check('карточка зовёт membersByGroups, а не groupMembers', fn.indexOf('_pubCall("membersByGroups"') > 0 && fn.indexOf('_pubCall("groupMembers"') < 0);
check('ошибка чтения группы не проглатывается', fn.indexOf('jb.errors') > 0);
check('«Failed to fetch» объясняется по-человечески', html.indexOf('соединение оборвалось, ответа не было') > 0);
// полный справочник клиентов — самый тяжёлый запрос прокси; карточке нужны только дети группы
check('карточка читает детей по id, а не весь справочник', fn.indexOf('_pubCall("customersByIds"') > 0 && fn.indexOf('_pubEnsureCustomers(') < 0);
check('уже загруженный справочник используется бесплатно', fn.indexOf('if(_pubCustomers && !force)') > 0);

console.log(bad ? '\n❌ провалено проверок: ' + bad : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
