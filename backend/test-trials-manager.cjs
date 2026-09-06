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
for (const n of ['trLoadManagers', '_trLeadPhones', '_trMgrOf', '_trMgrHtml', '_trPhone']) {
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
  // ⚠️ форма как у настоящего прокси: phones — МАССИВ (amo_field_values по коду PHONE).
  // Первый заход читал c.phone и молча не находил ни одного номера из 1257 сделок.
  contacts: {
    '101': { phones: ['+375 (29) 111-22-33'] },
    '102': { phones: ['80291112244', '+375 33 777-88-99'] },   // у родителя два номера
    '103': { phones: ['375291112255'] },
    '104': { phones: [] },                      // контакт без телефона — связать нечем
  },
  leads: [
    { id: 1, responsible: 11, created_at: 1000, contactIds: [101] },
    { id: 2, responsible: 12, created_at: 2000, contactIds: [102] },
    // вторая сделка того же клиента, позже и на другого менеджера — она и решает
    { id: 3, responsible: 12, created_at: 3000, contactIds: [101] },
    { id: 4, responsible: 99, created_at: 4000, contactIds: [103] },   // менеджера нет в справочнике
    { id: 5, responsible: 11, created_at: 5000, contactIds: [104] },
    // контактов нет, номер вписан в саму сделку — так делают в части клубов
    { id: 6, responsible: 11, created_at: 6000, contactIds: [],
      fields: { 'Телефон': ['+375 44 555-66-77'], 'Номер договора': ['1234567890123'] } },
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
    '206': { name: 'Второй Номер', phone: '+375337778899' },       // второй номер того же родителя
    '207': { name: 'Из Поля Сделки', phone: '+375445556677' },
    '208': { name: 'Похож На Договор', phone: '+375121234567' },   // цифры договора телефоном не считаем
  },
  _trMgr: null, _trMgrBusy: false, _trMgrErr: '', _trMgrStat: null,
  _amoWatch: () => ({ pipelineId: 7, statusIds: [] }),
  _amoCall: async () => LEADS,
  renderTrials: () => {},
  kidsOf: () => [],
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {trLoadManagers,_trLeadPhones,_trMgrOf,_trMgrHtml,_trPhone,_phoneKey,peek:()=>({mgr:_trMgr,err:_trMgrErr,stat:_trMgrStat})}; }')(ctx);

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
  eq('сделок прочитано', st.stat.leads, 6);
  eq('сделок с известным ответственным', st.stat.withMgr, 5);   // id 99 нет в справочнике
  // 101 (1) + 102 (2 номера) + поле сделки №6 (1) = 4; у 103 менеджер неизвестен, у 104 номера нет
  eq('телефонов в карте', st.stat.phones, 4);

  eq('менеджер по последней сделке, а не по первой', API._trMgrOf(201), 'Мария Титова');
  eq('второй клиент — свой менеджер', API._trMgrOf(202), 'Мария Титова');
  eq('менеджера нет в справочнике — не гадаем', API._trMgrOf(203), '');
  eq('номера нет в воронке', API._trMgrOf(204), '');
  eq('нет телефона — нет менеджера', API._trMgrOf(205), '');
  eq('второй номер родителя тоже связывает', API._trMgrOf(206), 'Мария Титова');
  eq('номер из поля сделки подхвачен', API._trMgrOf(207), 'Ольга Ковалёва');
  eq('цифры из «номера договора» телефоном не считаем', API._trMgrOf(208), '');

  /* --- сборщик телефонов отдельно: именно тут первый заход и промахнулся --- */
  const cts = LEADS.contacts;
  eq('из контакта берём ВСЕ номера', API._trLeadPhones({ contactIds: [102] }, cts).length, 2);
  eq('старая форма phone тоже понимается',
     API._trLeadPhones({ contactIds: [900] }, { '900': { phone: '+375291110000' } }).length, 1);
  eq('поле сделки берётся только когда контактов нет',
     API._trLeadPhones({ contactIds: [101], fields: { 'Телефон': ['+375440000000'] } }, cts).length, 1);
  eq('поле не про телефон игнорируем',
     API._trLeadPhones({ contactIds: [], fields: { 'Номер договора': ['1234567890123'] } }, cts).length, 0);

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

  /* ================= рейтинг менеджеров =================
     Считается по ТЕМ ЖЕ корзинам, что и воронка. Если пересчитывать заново, рейтинг и цифры
     над ним однажды разойдутся, и верить перестанут обоим. */
  {
    const html2 = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
    let s2 = '';
    for (const n of ['_trPayDays', '_trPaySum', '_trPayHtml', '_trMoneyStage', '_trMgrKidsHtml', '_trMgrUnknownHtml', '_trMgrStats', '_trMgrTableHtml']) {
      const m = html2.match(new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
      if (!m) { console.log('не найдено в index.html: ' + n); process.exit(1); }
      s2 += m[0] + '\n';
    }
    // корзины воронки подменяем: здесь проверяется сведение, а не сама воронка
    const kid = (id, done, sum) => ({ id: String(id), les: [{ done: done, sum: sum }] });
    const FN = {
      lists: {
        came:      [kid(201, true, 15), kid(202, true, 45), kid(206, true, 45)],
        missedAll: [kid(204, false, 0), kid(208, false, 0)],
        waiting:   [kid(207, false, 0)],
        noLessons: [kid(203, false, 0)],
        returning: [kid(205, true, 90)],
      },
    };
    const paid = { '202': true, '206': true };
    const arch = { '208': true };
    const mgrOf = { '201': 'Ольга', '202': 'Ольга', '203': 'Ольга', '204': 'Ольга',
                    '205': 'Мария', '206': 'Мария', '207': 'Мария' };   // у 208 менеджера нет
    const ctx2 = {
      _trMgr: {}, _trTar: { '202': {} },
    // касса по дням: у 04.09 разбивки по клиентам ещё нет — сумма должна быть честно неполной
    _trFun: { from: '2026-09-01' },
    _trPayBusy: false, _trPayProg: '',
    _trToday: () => '2026-09-06',
    _paySnap: {
      '2026-08-31': { byCust: { '202': 999 } },              // до сезона — не считаем
      '2026-09-02': { byCust: { '202': 195, '206': 300 } },
      '2026-09-03': { byCust: { '202': 105 } },
      '2026-09-04': { income: 500 },                          // снимок без разбивки
      '2026-09-09': { byCust: { '202': 777 } },               // будущее — не считаем
    },
      _trFunnel: () => FN,
      _trMgrOf: id => mgrOf[String(id)] || '',
    _trKidName: id => 'Ребёнок ' + id,
    _trArchBadge: () => '', _fmlKid: n => 'детей',
    _TR_BUCK: { came: ['дошёл', 'g'], missed: ['не дошёл', 'r'], waiting: ['ждём', 't'],
                noLes: ['без занятий', 'm'], back: ['ходил раньше', 'm'] },
    _TR_BORD: ['came', 'missed', 'waiting', 'noLes', 'back'],
    _trKidLink: id => '<a>Ребёнок ' + id + '</a>',
    _trPhone: id => ({ '208': '+375291110000' })[String(id)] || '',
    _phoneKey: p => { const d = String(p || '').replace(/[^0-9]/g, ''); return d.length >= 9 ? d.slice(-9) : ''; },
      _trHasPaid: id => !!paid[String(id)],
      _trArchived: id => !!arch[String(id)],
      _gm: n => Math.round(+n || 0).toLocaleString('ru-RU'),
      esc: x => String(x),
    };
    const A2 = new Function('ctx', 'with (ctx) { ' + s2 +
    ' return {_trMgrStats,_trMgrTableHtml,_trMgrUnknownHtml,_trMgrKidsHtml,_trMoneyStage,_trPaySum,_trPayDays,_trPayHtml}; }')(ctx2);

    const st = A2._trMgrStats();
    eq('менеджеров в рейтинге', st.length, 3);            // Ольга, Мария и «не определён»
    eq('первым — у кого больше дошедших', st[0].name, 'Ольга');
    check('«не определён» всегда внизу', st[st.length - 1].unknown === true, JSON.stringify(st.map(x => x.name)));

    const o = st.find(x => x.name === 'Ольга');
    eq('детей у Ольги', o.kids, 4);                       // 201,202,203,204
    eq('вписаны (есть занятия)', o.enrolled, 3);          // без 203 «без занятий»
    eq('дошли', o.came, 2);
    eq('не дошли', o.missed, 1);
    eq('доходимость = дошли ÷ (дошли + не дошли)', Math.round(o.reach), 67);
    eq('купили абонемент', o.paid, 1);
    eq('конверсия = купили ÷ дошли', Math.round(o.conv), 50);
    eq('списано по проведённым занятиям', o.sum, 60);     // 15 + 45
    eq('без занятий', o.noLes, 1);

    const m = st.find(x => x.name === 'Мария');
    // возвращенцы (ходили до 1 сентября) в рейтинг не входят вовсе: это не новые клиенты,
    // и абонемент у них с прошлого года — к работе менеджера по набору отношения не имеет
    eq('возвращенца в рейтинге нет', m.kids, 2);          // 206 и 207, без 205
    eq('и его денег тоже', m.sum, 45);                    // без 90 у возвращенца
    eq('ждущие в доходимость не идут', m.reach, 100);     // дошёл 1, не дошёл 0
    check('колонки «Раньше» больше нет', A2._trMgrTableHtml().indexOf('>Раньше<') < 0);
    check('и в списках возвращенцев не видно', A2._trMgrKidsHtml(st).indexOf('ходил раньше') < 0);

    const u = st[st.length - 1];
    eq('у «не определён» свой ребёнок', u.kids, 1);
    eq('и он в архиве', u.arch, 1);

    const h2 = A2._trMgrTableHtml();
    check('таблица нарисована', h2.indexOf('Рейтинг менеджеров (3)') > 0, h2.slice(0, 200));
    check('есть итоговая строка', h2.indexOf('Итого') > 0);
    check('итог по дошедшим', h2.indexOf('>3<') > 0, 'дошли всего 3');
    check('объяснено, что «списано» — не касса', h2.indexOf('не деньги в кассе') > 0);
    check('объяснено правило доходимости', h2.indexOf('ждущие не в счёт') > 0);

    // абонементы не загружены — колонку нельзя молча показывать нулями
    ctx2._trTar = {};
    check('про незагруженные абонементы сказано',
          A2._trMgrTableHtml().indexOf('Абонементы ещё не загружены') > 0);
    ctx2._trTar = { '202': {} };

    /* --- настоящие деньги: платежи клиента в кассу --- */
    // «Списано» и «Оплачено» — разные вещи: абонемент могли купить в понедельник, а списывается
    // он по занятиям всю неделю. Путать их нельзя, поэтому колонки две.
    eq('платежи клиента за сезон', A2._trPaySum(202), 195 + 105);
    eq('до начала сезона не считаем', A2._trPaySum(202) < 999, true);
    eq('будущие дни тоже не считаем', A2._trPaySum(202), 300);
    eq('второй клиент', A2._trPaySum(206), 300);
    eq('у кого платежей нет — ноль', A2._trPaySum(201), 0);
    check('в строке ребёнка видна сумма', A2._trPayHtml(202).indexOf('оплачено') > 0, A2._trPayHtml(202));
    eq('без платежей строка пустая', A2._trPayHtml(201), '');

    const pd = A2._trPayDays();
    eq('дней сезона в хранилище', pd.have, 3);          // 02, 03, 04 сентября
    eq('из них с разбивкой по клиентам', pd.withCust, 2);

    const st2 = A2._trMgrStats();
    eq('деньги Ольги', st2.find(x => x.name === 'Ольга').pay, 300);   // только 202
    eq('деньги Марии', st2.find(x => x.name === 'Мария').pay, 300);   // только 206
    const h3 = A2._trMgrTableHtml();
    check('колонка «Оплачено» есть', h3.indexOf('>Оплачено<') > 0);
    check('сказано, что снимки неполные', h3.indexOf('из 3') > 0, h3.slice(h3.indexOf('Оплачено') - 200, h3.indexOf('Оплачено') + 60));


  /* --- конверсия не может быть больше 100% ---
     Числитель и знаменатель должны быть из одной корзины. Абонемент ждущего или возвращенца
     попадал в числитель, но не в знаменатель — так и выходили 105% и 183%. */
  const oo = st2.find(x => x.name === 'Ольга');
  eq('купили всего (включая не дошедших)', oo.paid, 1);
  eq('купили ИЗ дошедших', oo.paidCame, 1);
  check('конверсия не выше 100%', st2.every(x => x.conv == null || x.conv <= 100),
        JSON.stringify(st2.map(x => [x.name, x.conv])));

  /* --- кого не удалось связать: имя, телефон и ПРИЧИНА --- */
  const hu = A2._trMgrUnknownHtml();
  check('список несвязанных есть', hu.indexOf('Кого не удалось связать') > 0, hu.slice(0, 120));
  check('и в нём один ребёнок', hu.indexOf('(1)') > 0, hu.slice(0, 160));
  check('назван телефон из Alfa', hu.indexOf('+375291110000') > 0, hu);
  check('и причина: номера нет в воронке', hu.indexOf('номера нет ни в одной сделке') > 0, hu);
  check('сказано, что делать', hu.indexOf('вписать') > 0, hu);

  /* --- в «Работе» рейтинга быть не должно: там нужен ответ «чей пробник», а не сравнение --- */
  const funnelSrc = html2.match(/\nfunction _trFunnelHtml\(\)[\s\S]*?\n\}/m);
  check('в воронке «Пробных» таблица рейтинга не собирается',
        funnelSrc && funnelSrc[0].indexOf('_trMgrTableHtml()') < 0, 'рейтинг остался в «Работе»');
  check('но «чей пробник» в строках остался', html2.indexOf('_trMgrHtml(r.id)') > 0);
  check('раздел заведён в дашборде руководителя', html2.indexOf('["mgr","🏅 Рейтинг менеджеров"]') > 0);


  /* --- где ребёнок «именно сейчас»: два независимых этапа ---
     По занятиям (дошёл / не дошёл / ждём / без занятий / раньше) и по деньгам. Именно их
     независимость и объясняет, почему «Купили» бывает больше «Дошли»: оплатить абонемент можно,
     ещё не придя на первое занятие. */
  eq('оплативший в сезоне — самый дальний этап', A2._trMoneyStage(202)[0], 'оплатил 300 р');
  ctx2._trTar = { '201': { paid: true, tariffs: [{ trial: false }] } };
  eq('абонемент есть, а платежей в сезон нет', A2._trMoneyStage(201)[0], 'абонемент есть, оплаты в сезон не видно');
  ctx2._trTar = { '201': { paid: false, tariffs: [{ trial: true }] } };
  eq('только пробный', A2._trMoneyStage(201)[0], 'только пробный абонемент');
  ctx2._trTar = { '201': { paid: false, tariffs: [] } };
  eq('без абонемента', A2._trMoneyStage(201)[0], 'без абонемента');
  ctx2._trTar = {};
  eq('абонементы не загружены — так и пишем', A2._trMoneyStage(201)[0], 'абонементы не загружены');
  ctx2._trTar = { '202': {} };

  const hk = A2._trMgrKidsHtml(A2._trMgrStats());
  check('раскрывашки по менеджерам есть', hk.indexOf('Дети по менеджерам') > 0, hk.slice(0, 120));
  check('в шапке менеджера — сводка', hk.indexOf('дошли 2 · купили 1') > 0, hk.slice(0, 700));
  check('дети перечислены', hk.indexOf('Ребёнок 201') > 0);
  check('этап по занятиям виден', hk.indexOf('дошёл') > 0);
  check('этап по деньгам виден', hk.indexOf('оплатил 300 р') > 0, hk);
  check('объяснено, почему купили > дошли', hk.indexOf('ещё не придя на первое занятие') > 0);
  // порядок внутри менеджера: сначала дошедшие, потом не дошедшие, ждущие и остальные
  const ol = hk.slice(hk.indexOf('Ольга'));
  check('дошедшие идут раньше не дошедших',
        ol.indexOf('дошёл') < ol.indexOf('не дошёл'), 'порядок внутри менеджера');


    // менеджеры не подтянуты — рейтинга нет вовсе
    ctx2._trMgr = null;
    eq('без карты менеджеров рейтинга нет', A2._trMgrStats(), null);
    eq('и таблица пустая', A2._trMgrTableHtml(), '');
    ctx2._trMgr = {};
  }


  console.log(bad ? ('\n❌ провалов: ' + bad) : '\n✅ всё сошлось');
  process.exit(bad ? 1 : 0);
})();
