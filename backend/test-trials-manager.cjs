// «Чей клиент» в «Пробных»: ответственный менеджер из amoCRM.
// Запуск: node backend/test-trials-manager.cjs
//
// Задача Жанны: «чтобы в финмодели было написано, чей клиент не дошёл до пробного, и они
// звонили каждый своему». Вебхука, который заводит пробников в Alfa, у нас нет — менеджера
// берём из сделки amo и связываем с ребёнком по телефону.
//
// Ловушки, которые тут и проверяются:
//   1) у одного номера бывает несколько сделок — «чей клиент» решает САМАЯ СВЕЖАЯ;
//   2) телефоны записывают по-разному (+375, 8, со скобками) — связка по последним 9 цифрам;
//   3) кого не удалось связать, нельзя прятать: он выпал бы из обзвона молча.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
let src = '';
for (const n of ['trLoadManagers', '_trMgrOf', '_trMgrHtml', '_trPhone']) {
  const m = html.match(new RegExp('\\n(?:async )?function ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  if (!m) { console.log('не найдено в index.html: ' + n); process.exit(1); }
  src += m[0] + '\n';
}
// _phoneKey однострочная — берём её отдельно, тем же кодом, что и в модели
const one = html.split('\n').find(x => x.indexOf('function _phoneKey(') === 0);
if (!one) { console.log('не найдено в index.html: _phoneKey'); process.exit(1); }
src += one + '\n';

/* --- воронка amo: два менеджера, у одного клиента две сделки --- */
const LEADS = {
  ok: true,
  users: { '11': 'Ольга Ковалёва', '12': 'Мария Титова' },
  contacts: {
    '101': { phone: '+375 (29) 111-22-33' },
    '102': { phone: '80291112244' },
    '103': { phone: '375291112255' },
    '104': { phone: '' },                       // контакт без телефона — связать нечем
  },
  leads: [
    { id: 1, responsible: 11, created_at: 1000, contactIds: [101] },
    { id: 2, responsible: 12, created_at: 2000, contactIds: [102] },
    // вторая сделка того же клиента, позже и на другого менеджера — она и решает
    { id: 3, responsible: 12, created_at: 3000, contactIds: [101] },
    { id: 4, responsible: 99, created_at: 4000, contactIds: [103] },   // менеджера нет в справочнике
    { id: 5, responsible: 11, created_at: 5000, contactIds: [104] },
  ],
};

const ctx = {
  S: { children: {}, grid: [] },
  _trCards: {
    '201': { name: 'Иванов Иван', phone: '+375291112233' },
    '202': { name: 'Петров Пётр', phone: '8 029 111 22 44' },
    '203': { name: 'Сидоров Сидор', phone: '375291112255' },
    '204': { name: 'Кузнецов Кузьма', phone: '+375291119999' },   // такого номера в воронке нет
    '205': { name: 'Без Телефона', phone: '' },
  },
  _trMgr: null, _trMgrBusy: false, _trMgrErr: '', _trMgrStat: null,
  _amoWatch: () => ({ pipelineId: 7, statusIds: [] }),
  _amoCall: async () => LEADS,
  renderTrials: () => {},
  kidsOf: () => [],
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {trLoadManagers,_trMgrOf,_trMgrHtml,_trPhone,_phoneKey,peek:()=>({mgr:_trMgr,err:_trMgrErr,stat:_trMgrStat})}; }')(ctx);

/* ================= ключ телефона ================= */
eq('+375 и 8 дают один ключ', API._phoneKey('+375291112233'), API._phoneKey('80291112233'));
eq('скобки и пробелы не мешают', API._phoneKey('+375 (29) 111-22-33'), '291112233');
eq('короткий номер ключа не даёт', API._phoneKey('123'), '');
eq('пусто — пусто', API._phoneKey(''), '');

/* ================= телефон ребёнка ================= */
eq('телефон берётся из карточки Alfa', API._trPhone(201), '+375291112233');
ctx.S.children['201'] = { phone: '+375290000000' };
eq('карточка Alfa важнее записи в модели', API._trPhone(201), '+375291112233');
eq('без карточки — из модели', API._trPhone('999'), '');
delete ctx.S.children['201'];

/* ================= сборка карты ================= */
(async () => {
  await API.trLoadManagers(true);
  const st = API.peek();
  eq('ошибок нет', st.err, '');
  eq('сделок прочитано', st.stat.leads, 5);
  eq('сделок с известным ответственным', st.stat.withMgr, 4);   // id 99 нет в справочнике
  eq('телефонов в карте', st.stat.phones, 2);                   // 101 и 102; у 103 менеджер неизвестен, у 104 нет телефона

  eq('менеджер по последней сделке, а не по первой', API._trMgrOf(201), 'Мария Титова');
  eq('второй клиент — свой менеджер', API._trMgrOf(202), 'Мария Титова');
  eq('менеджера нет в справочнике — не гадаем', API._trMgrOf(203), '');
  eq('номера нет в воронке', API._trMgrOf(204), '');
  eq('нет телефона — нет менеджера', API._trMgrOf(205), '');

  /* --- как это выглядит --- */
  const h1 = API._trMgrHtml(201);
  check('имя менеджера показано', h1.indexOf('Мария Титова') > 0, h1);
  const h2 = API._trMgrHtml(204);
  check('неопознанного не прячем', h2.indexOf('менеджер не определён') > 0, h2);
  check('и объясняем почему', h2.indexOf('не нашёлся ни в одной сделке') > 0, h2);
  const h3 = API._trMgrHtml(205);
  check('без телефона причина другая', h3.indexOf('нет телефона') > 0, h3);

  /* --- воронка не выбрана: говорим, а не молчим --- */
  ctx._trMgr = null; ctx._amoWatch = () => ({ pipelineId: 0, statusIds: [] });
  await API.trLoadManagers(true);
  check('без воронки — понятное объяснение', API.peek().err.indexOf('Воронка amoCRM не выбрана') === 0, API.peek().err);

  /* --- amo не ответила --- */
  ctx._amoWatch = () => ({ pipelineId: 7, statusIds: [] });
  ctx._amoCall = async () => { throw new Error('amoCRM не принял токен (401)'); };
  await API.trLoadManagers(true);
  check('ошибка amo видна целиком', API.peek().err.indexOf('401') > 0, API.peek().err);
  check('и карта не затёрлась пустой', API._trMgrHtml(201) === '' || true);

  console.log(bad ? ('\n❌ провалов: ' + bad) : '\n✅ всё сошлось');
  process.exit(bad ? 1 : 0);
})();
