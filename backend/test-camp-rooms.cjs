// Расселение по лагерю: сколько мест считать и что показывать на месте ребёнка.
// Запуск: node backend/test-camp-rooms.cjs
//
// Проверяется НАСТОЯЩИЙ код из index.html — функции вырезаются из файла и исполняются.
//
// Правило Жанны 09.09.2026: «в расселении не участвуют вожатые, не учитывай их — только блоки
// для детей, в которых я пометила мальчик или девочка. Если пол не задан — не считай его в этом
// показателе». Отсюда три разные цифры, и путать их нельзя:
//   вписано — детей уже на местах;
//   нужно   — места в детских блоках, У КОТОРЫХ ЗАДАН ПОЛ;
//   всего   — все детские места санатория, кроме «Комфорта» (блоки вожатых).
// Раньше знаменатель был один — все 76 детских мест, и «1 / 76» читалось как огромный недобор,
// хотя размечен был ровно один блок на четверых.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
/* Справочник статусов берём из index.html, а не переписываем сюда: заглушка однажды уже
   показала старый цвет кружка и проверка прошла на правке, которой в коде не было. */
const ST = eval('(' + (html.match(/const CAMP_ST=(\{.*?\});/) || [])[1] + ')');
const MULTI = ['_campSeedLayout', '_campCap', '_campBeds', '_campSplitFloor2', '_campWhyNoTransfer', '_campTarLabel', '_campSubjIds', '_campLtIds',
               '_campPrepaid', '_campDot', '_campGrpFrom'];
const ONE = ['_campNewId', '_campInGroup', '_campTarPrice'];
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of MULTI) grab(n, new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
for (const n of ONE) grab(n, new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));

let seq = 0;
const ctx = { _campNewIdSeq: 0, _gm: n => String(Math.round(+n||0)), _campAlfaGroups: null, _campAlfaTariffs: null,
  CAMP_ST: ST,
  esc: s => String(s == null ? '' : s),
  _campToday: () => '2026-09-11',
  // тип урока может подставляться из настроек публикации — заглушка их «не настроенными»
  _pubCfg: () => ({}) };
/* _campBlockHtml рисует карточку блока — вырезаем отдельно, у него свой набор заглушек. */
const mb = html.match(/\nfunction _campBlockHtml\([^)]*\)\s*\{[\s\S]*?\n\}/m);
if (!mb) { console.log('не найдено в index.html: _campBlockHtml'); process.exit(1); }
/* ⚠️ _campBlockHtml рисует кружок настоящей _campDot — её и её напарницу берём из index.html,
   иначе заглушка нарисовала бы что угодно и проверка ничего бы не значила. */
let blockSrc = mb[0];
for (const n of ['_campDot', '_campPrepaid']) {
  const mm = html.match(new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  if (!mm) { console.log('не найдено в index.html: ' + n); process.exit(1); }
  blockSrc += '\n' + mm[0];
}
const API = new Function('ctx', 'with (ctx) { ' + src + ' return {_campSeedLayout,_campCap,_campBeds,_campSplitFloor2,_campWhyNoTransfer,_campInGroup,_campTarPrice,_campTarLabel,_campSubjIds,_campLtIds,_campPrepaid,_campDot,_campGrpFrom}; }')(ctx);

/* ================= схема санатория ================= */
const lay = API._campSeedLayout();
const kid = (lay.blocks || []).filter(b => !b.staff);
const staff = (lay.blocks || []).filter(b => b.staff);
const capOf = b => (b.rooms || []).reduce((s, r) => s + (+r.beds || 0), 0);

eq('детских блоков', kid.length, 15);          // 5 на втором этаже + 10 на третьем
eq('блоков вожатых («Комфорт»)', staff.length, 3);
eq('мест у вожатых', staff.reduce((s, b) => s + capOf(b), 0), 6);
// 2 этаж: 3×4 + 2×5 = 22; 3 этаж: 8 блоков по 3+3 = 48, плюс к.40 и к.44 по 3 = 54
eq('детских мест всего', kid.reduce((s, b) => s + capOf(b), 0), 76);
eq('мест на 2 этаже', kid.filter(b => b.floor === '2 этаж').reduce((s, b) => s + capOf(b), 0), 22);
eq('мест на 3 этаже', kid.filter(b => b.floor === '3 этаж').reduce((s, b) => s + capOf(b), 0), 54);
// ⚠️ _campBeds(lay,true) — «только детские»: вожатых в расселении нет вовсе
eq('коек всего', API._campBeds(lay).length, 82);
eq('из них детских', API._campBeds(lay, true).length, 76);

/* ================= три цифры ================= */
{
  // ничего не размечено: «нужно заполнить» — ноль, а не 76
  const C0 = API._campCap({ sex: {} }, lay);
  eq('всего мест кроме комфорта', C0.all, 76);
  eq('нужно заполнить, пока пол нигде не задан', C0.need, 0);
  eq('размеченных блоков нет', C0.marked, 0);
  eq('а всего детских блоков', C0.blocks, 15);

  // живой случай со скриншота: помечен один блок 2.1 на четверых
  const b21 = kid.find(b => b.name === 'Блок 2.1');
  check('блок 2.1 найден', !!b21, kid.map(b => b.name).join(', '));
  const C1 = API._campCap({ sex: { [b21.id]: 'm' } }, lay);
  eq('нужно заполнить = места размеченного блока', C1.need, 4);
  eq('размечен один блок', C1.marked, 1);
  eq('всего не изменилось', C1.all, 76);

  // ⚠️ блок вожатых пометить полом можно только по ошибке — в счёт он не идёт НИКОГДА
  const cf = staff[0];
  const C2 = API._campCap({ sex: { [b21.id]: 'm', [cf.id]: 'f' } }, lay);
  eq('«Комфорт» в «нужно заполнить» не попал', C2.need, 4);
  eq('и в «всего» его тоже нет', C2.all, 76);
  eq('и в числе блоков не считается', C2.blocks, 15);

  // размечаем весь второй этаж — нужно ровно его 22 места
  const sex2 = {};
  kid.filter(b => b.floor === '2 этаж').forEach(b => { sex2[b.id] = 'm'; });
  eq('весь 2 этаж размечен', API._campCap({ sex: sex2 }, lay).need, 22);
  eq('и это 5 блоков', API._campCap({ sex: sex2 }, lay).marked, 5);

  // размечено всё — «нужно» сходится с «всего»
  const sexAll = {};
  kid.forEach(b => { sexAll[b.id] = 'f'; });
  const C3 = API._campCap({ sex: sexAll }, lay);
  eq('размечено всё — нужно = всего', C3.need, C3.all);
  eq('и блоков поровну', C3.marked, C3.blocks);

  // ⚠️ пустая строка в sex — это «пол не задан», а не «задан пустым»
  const C4 = API._campCap({ sex: { [b21.id]: '' } }, lay);
  eq('пустой пол не считается заданным', C4.need, 0);

  // смены без sex вовсе (старые данные) не должны ронять расчёт
  eq('смена без поля sex', API._campCap({}, lay).need, 0);
  eq('и без схемы', API._campCap({ sex: {} }, null).all, 0);
}

/* ================= схема без вожатых и пустая ================= */
{
  const empty = { id: 'L0', name: 'пусто', blocks: [] };
  const C = API._campCap({ sex: {} }, empty);
  eq('пустая схема: всего', C.all, 0);
  eq('пустая схема: блоков', C.blocks, 0);
  // блок без комнат — блок есть, мест нет: считаем блок, но не место
  const one = { id: 'L1', blocks: [{ id: 'b1', name: 'Блок', rooms: [] }] };
  eq('блок без комнат не даёт мест', API._campCap({ sex: { b1: 'm' } }, one).need, 0);
  eq('но сам блок посчитан', API._campCap({ sex: { b1: 'm' } }, one).blocks, 1);
}

/* ================= РАЗБИВКА БЛОКОВ 2 ЭТАЖА НА КОМНАТЫ =================
   Просьба Жанны 09.09.2026: «сделай на 2 этаже тоже разбивку на комнаты как и на 3-м».
   Схема живёт в данных, а не в коде, поэтому мало поправить _campSeedLayout — надо разрезать
   уже настроенные схемы.
   ⚠️ Дети привязаны к месту ключом «блок|комната|номер». Разрежешь комнату, не тронув ключи, —
   и все, кто сидел дальше нового размера первой комнаты, молча выпадут в «не расселённые».
   Общее число мест меняться не должно НИ НА ОДНО: 4 = 2+2, 5 = 3+2. */
{
  const beds = b => (b.rooms || []).reduce((s, r) => s + (+r.beds || 0), 0);

  /* --- новая схема сразу с комнатами --- */
  const lay = API._campSeedLayout();
  const f2 = (lay.blocks || []).filter(b => b.floor === '2 этаж' && !b.staff);
  eq('детских блоков на 2 этаже', f2.length, 5);
  check('у каждого по две комнаты', f2.every(b => (b.rooms || []).length === 2),
        f2.map(b => b.name + ':' + (b.rooms || []).length).join(', '));
  eq('мест на 2 этаже не изменилось', f2.reduce((s, b) => s + beds(b), 0), 22);
  eq('блок на 4 разбит как 2+2', (f2.find(b => b.name === 'Блок 2.1').rooms || []).map(r => r.beds).join('+'), '2+2');
  eq('блок на 5 разбит как 3+2', (f2.find(b => b.name === 'Блок 2.4').rooms || []).map(r => r.beds).join('+'), '3+2');
  // ⚠️ «Комфорт» не делим: там два места вожатым, комната одна
  const cf = (lay.blocks || []).filter(b => b.staff);
  check('«Комфорт» остался одной комнатой', cf.every(b => (b.rooms || []).length === 1),
        cf.map(b => b.name + ':' + (b.rooms || []).length).join(', '));
  eq('и мест у вожатых столько же', cf.reduce((s, b) => s + beds(b), 0), 6);
  // третий этаж не трогали
  eq('на 3 этаже по-прежнему 54', (lay.blocks || []).filter(b => b.floor === '3 этаж')
     .reduce((s, b) => s + beds(b), 0), 54);

  /* --- миграция СТАРОЙ схемы: блоки одной комнатой --- */
  const old = () => ({
    layouts: [{ id: 'L', blocks: [
      { id: 'b1', floor: '2 этаж', name: 'Блок 2.1', rooms: [{ id: 'r1', name: 'комната', beds: 4 }] },
      { id: 'b4', floor: '2 этаж', name: 'Блок 2.4', rooms: [{ id: 'r1', name: 'комната', beds: 5 }] },
      { id: 'c1', floor: '2 этаж', name: 'Комфорт 1', staff: true, rooms: [{ id: 'r1', name: 'комната', beds: 2 }] },
      { id: 'b32', floor: '3 этаж', name: 'Блок 32', rooms: [{ id: 'r1', name: 'комната 1', beds: 3 }, { id: 'r2', name: 'комната 2', beds: 3 }] },
    ] }],
    res: [
      { id: 'k0', fio: 'Мельников Илья', bed: 'b1|r1|0' },   // остаётся в первой комнате
      { id: 'k1', fio: 'Второй',         bed: 'b1|r1|1' },   // тоже
      { id: 'k2', fio: 'Третий',         bed: 'b1|r1|2' },   // ⚠️ уезжает во вторую: 2 → r2|0
      { id: 'k3', fio: 'Четвёртый',      bed: 'b1|r1|3' },   // ⚠️ и этот: 3 → r2|1
      { id: 'k5', fio: 'Пятый',          bed: 'b4|r1|4' },   // из блока на 5: 4 → r2|1
      { id: 'k9', fio: 'На третьем',     bed: 'b32|r2|0' },  // чужой этаж — не трогать
      { id: 'kx', fio: 'Без места',      bed: '' },
    ],
  });

  const c = old();
  eq('миграция сработала', API._campSplitFloor2(c), true);
  const B = id => c.layouts[0].blocks.find(b => b.id === id);
  eq('блок 2.1 стал двумя комнатами', (B('b1').rooms || []).length, 2);
  eq('и мест в нём столько же', beds(B('b1')), 4);
  eq('блок 2.4 тоже', beds(B('b4')), 5);
  eq('и разбит 3+2', (B('b4').rooms || []).map(r => r.beds).join('+'), '3+2');
  eq('«Комфорт» не тронут', (B('c1').rooms || []).length, 1);
  eq('третий этаж не тронут', (B('b32').rooms || []).length, 2);

  const bedOf = id => (c.res.find(r => r.id === id) || {}).bed;
  // ⚠️ вот ради чего всё: никто не должен выпасть из своего блока
  eq('первый остался на месте', bedOf('k0'), 'b1|r1|0');
  eq('второй тоже', bedOf('k1'), 'b1|r1|1');
  eq('третий переехал во вторую комнату', bedOf('k2'), 'b1|r2|0');
  eq('четвёртый следом', bedOf('k3'), 'b1|r2|1');
  eq('пятый из блока на 5', bedOf('k5'), 'b4|r2|1');
  eq('на третьем этаже место не тронуто', bedOf('k9'), 'b32|r2|0');
  eq('без места так и остался без места', bedOf('kx'), '');
  // никто не потерялся и не сел на чужое место
  const taken = c.res.filter(r => r.bed).map(r => r.bed);
  eq('расселённых столько же', taken.length, 6);
  eq('и все на разных местах', new Set(taken).size, 6);

  /* --- повторный прогон ничего не меняет --- */
  eq('второй раз миграция не срабатывает', API._campSplitFloor2(c), false);
  eq('и мест не прибавилось', beds(B('b1')), 4);
  eq('и никто не переехал снова', bedOf('k2'), 'b1|r2|0');

  /* --- блок на 3 места делить нечего --- */
  const small = { layouts: [{ id: 'L', blocks: [
    { id: 's1', floor: '2 этаж', name: 'Малый', rooms: [{ id: 'r1', name: 'комната', beds: 3 }] } ] }], res: [] };
  eq('блок на 3 не делим', API._campSplitFloor2(small), false);
  eq('и он остался одной комнатой', (small.layouts[0].blocks[0].rooms || []).length, 1);

  /* --- ⚠️ id второй комнаты не должен столкнуться с существующим --- */
  const clash = { layouts: [{ id: 'L', blocks: [
    { id: 'x', floor: '2 этаж', name: 'Странный', rooms: [{ id: 'r2', name: 'комната', beds: 4 }] } ] }],
    res: [{ id: 'q', bed: 'x|r2|3' }] };
  eq('разбили и такой', API._campSplitFloor2(clash), true);
  const rm = clash.layouts[0].blocks[0].rooms;
  eq('комнат две', rm.length, 2);
  check('идентификаторы разные', rm[0].id !== rm[1].id, rm.map(r => r.id).join(','));
  eq('и ребёнок переехал в новую', clash.res[0].bed, 'x|' + rm[1].id + '|1');
}

/* ================= КАРТОЧКА БЛОКА: ВОЗРАСТ РЯДОМ С ИМЕНЕМ =================
   Просьба Жанны 09.09.2026: «в карточке с блоками у ребёнка рядом тоже ставь возраст».
   В списке «Не расселены» возраст был, а на самом месте — нет. А нужен он именно там: в комнату
   к шестилеткам не селят двенадцатилетнего, и проверять это глазами приходится по карточкам. */
{
  const B = new Function('ctx', 'with (ctx) { ' + blockSrc + ' return {_campBlockHtml}; }')({
    _campEdit: false, _campPick: null,
    CAMP_ST: ST,
    esc: s => String(s == null ? '' : s),
    _jsStr: s => String(s == null ? '' : s),
    _ageStr: d => (d === '2014-08-07' ? '12,1' : (d === '2018-01-15' ? '8,7' : '')),
  });

  const blk = { id: 'b1', name: 'Блок 2.1', floor: '2 этаж', staff: false, rooms: [{ id: 'r1', name: 'комната', beds: 3 }] };
  const sh = { sex: { b1: 'm' }, staff: {} };
  const kid = { id: 'k1', fio: 'Мельников Илья', status: 'maybe', dob: '2018-01-15' };
  const h = B._campBlockHtml(sh, blk, { 'b1|r1|0': kid });

  check('имя ребёнка на месте', h.indexOf('Мельников Илья') > 0, h.slice(0, 300));
  check('и возраст рядом с ним', h.indexOf('<i class="cmp-kid-a">8,7</i>') > 0,
        (h.match(/<span class="cmp-bn">[\s\S]{0,120}/) || [''])[0]);
  // ⚠️ возраст должен быть ВНУТРИ имени, а не отдельной строкой — иначе место распухнет вдвое
  check('возраст внутри строки имени',
        // ⚠️ Внутри строки имени теперь живёт ещё и кружок статуса (со своим </span>) — но
        //    возраст обязан идти сразу за именем и закрывать ТУ ЖЕ строку, а не уезжать в свою.
        /<span class="cmp-bn">[\s\S]{0,160}Мельников Илья<i class="cmp-kid-a">8,7<\/i><\/span>/.test(h),
        (h.match(/<span class="cmp-bn">[\s\S]{0,140}/) || [''])[0]);
  check('свободные места подписаны', h.indexOf('свободно') > 0);
  check('счётчик блока', h.indexOf('1/3') > 0, (h.match(/cmp-cap">[^<]*/) || [''])[0]);

  // без даты рождения возраста нет — и пустого «<i>» тоже быть не должно
  const h2 = B._campBlockHtml(sh, blk, { 'b1|r1|0': { id: 'k2', fio: 'Без Даты', status: 'yes', dob: '' } });
  check('без даты рождения возраст не рисуем', h2.indexOf('cmp-kid-a') < 0,
        (h2.match(/<span class="cmp-bn">[\s\S]{0,120}/) || [''])[0]);
  check('но имя на месте', h2.indexOf('Без Даты') > 0);

  /* ⚠️ Кружок с галочкой нужен именно здесь: расселяя, Жанна должна видеть, за кем деньги.
     Эмодзи 🟢 тут больше нет — в него галочку не поставить. */
  const hp = B._campBlockHtml(sh, blk, { 'b1|r1|0': { id: 'k3', fio: 'Оплативший Пётр', status: 'yes', pre: 50 } });
  check('на месте ребёнка кружок, а не эмодзи', hp.indexOf('cmp-dot') > 0 && hp.indexOf('🟢') < 0,
        (hp.match(/<span class="cmp-bn">[\s\S]{0,160}/) || [''])[0]);
  check('и в нём галочка', hp.indexOf('<i>✓</i>') > 0, (hp.match(/<span class="cmp-bn">[\s\S]{0,160}/) || [''])[0]);
  check('у неоплатившего галочки нет', h.indexOf('✓') < 0, (h.match(/<span class="cmp-bn">[\s\S]{0,160}/) || [''])[0]);
  check('подсказка места говорит про деньги', hp.indexOf('деньги внесены') > 0, (hp.match(/title="[^"]*/) || [''])[0]);

  /* И в списке «Не расселены» — тот же знак: там и там одно и то же читается одинаково. */
  const freeSrc = (html.match(/\nfunction renderCampRooms\(\)[\s\S]*?\n\}/m) || [''])[0];
  check('у нерасселённых тоже кружок', freeSrc.indexOf('+_campDot(r)+') > 0, freeSrc.slice(0, 400));
  check('и эмодзи там не осталось', freeSrc.indexOf('+st.ic+') < 0, freeSrc.slice(0, 400));

  /* ⚠️ Блок вожатых — отдельная история: там не дети, а поля для ФИО, и ни возраста, ни
     выбора пола быть не должно. Именно эти блоки не участвуют в расселении. */
  const cf = { id: 'c1', name: 'Комфорт 1', floor: '2 этаж', staff: true, rooms: [{ id: 'r1', name: 'комната', beds: 2 }] };
  const h3 = B._campBlockHtml({ sex: {}, staff: { 'c1|r1|0': 'Иванова А.' } }, cf, {});
  check('у вожатых поле ФИО', h3.indexOf('вожат') > 0, h3.slice(0, 260));
  check('и нет выбора пола', h3.indexOf('— пол не задан —') < 0);
  check('и подпись «вожатые»', h3.indexOf('вожатые · 2') > 0, (h3.match(/cmp-cap">[^<]*/) || [''])[0]);
}

/* ================= ГАЛОЧКА В КРУЖОЧКЕ: ПОДТВЕРЖДЕНО И ОПЛАЧЕНО =================
   Жанна 11.09.2026: «у детей, у которых предоплата, внутри кружочка ставь галочку — так мы
   будем знать, что подтверждено и предоплачено». Цвет кружка отвечает на «подтвердил ли»,
   галочка — на «заплатил ли»; это один знак, а не два соседних значка. */
{
  eq('без денег — не оплачен', API._campPrepaid({ status: 'yes' }), false);
  eq('предоплата — оплачен', API._campPrepaid({ pre: 50 }), true);
  // ⚠️ Родитель мог внести сразу всю сумму: отдельной предоплаты у него нет, а место закреплено
  eq('полная оплата без предоплаты — тоже', API._campPrepaid({ paid: 300 }), true);
  eq('нули деньгами не считаем', API._campPrepaid({ pre: 0, paid: 0 }), false);
  eq('пустые строки тоже', API._campPrepaid({ pre: '', paid: '' }), false);
  eq('строка с числом — считаем', API._campPrepaid({ pre: '50' }), true);
  eq('строки нет вовсе', API._campPrepaid(null), false);

  const dPaid = API._campDot({ status: 'yes', pre: 50 });
  const dFree = API._campDot({ status: 'yes' });
  check('у оплатившего галочка в кружке', dPaid.indexOf('<i>✓</i>') > 0, dPaid);
  check('у неоплатившего галочки нет', dFree.indexOf('✓') < 0, dFree);
  check('кружок один и тот же', dFree.indexOf('cmp-dot') > 0, dFree);
  // цвет по-прежнему отвечает за статус — галочка его не подменяет
  /* Жанна 11.09.2026: «сделай цвет зелёного как во втором скрине» — там кружок 🟢 из
     выпадающего списка статуса. Замерили его в Segoe UI Emoji: #16C60C. */
  check('подтвердил — зелёный как у 🟢', dPaid.indexOf('#16C60C') > 0, dPaid);
  /* ⚠️ Заливка кружка и цвет ТЕКСТА — разные вещи. Тем же значением подписан статус
     в выпадающих списках, и яркий зелёный на белом читается там хуже тёмного. */
  eq('а слово «подтвердил» осталось тёмным', ST.yes.c, 'var(--green)');
  check('кружок берёт не цвет текста', dPaid.indexOf('var(--green)') < 0, dPaid);
  /* У «думает» своего цвета кружка нет — эмодзи 🟡 #FFF100 на белом фоне почти теряется,
     поэтому там остаётся фирменная охра. */
  eq('у «думает» отдельного цвета кружка нет', ST.maybe.dot, undefined);
  check('думает — охра', API._campDot({ status: 'maybe', pre: 50 }).indexOf('#C9922E') > 0, API._campDot({ status: 'maybe', pre: 50 }));
  check('отказ — красный', API._campDot({ status: 'no' }).indexOf('var(--red)') > 0, API._campDot({ status: 'no' }));
  eq('статуса нет — как «думает»', API._campDot({}).indexOf('#C9922E') > 0, true);
  // подсказка должна говорить обе вещи, а не одну
  check('в подсказке и статус, и деньги', dPaid.indexOf('подтвердил · деньги внесены') > 0, dPaid);
  check('и когда денег нет — тоже', dFree.indexOf('оплаты пока нет') > 0, dFree);
}

/* ================= С КАКОЙ ДАТЫ ЧИСЛИТЬ В ГРУППЕ ALFA =================
   Жанна 11.09.2026: «в группу добавь с даты внесения до 7 ноября». Не с первого дня смены:
   иначе до самого заезда группа в Alfa выглядит пустой и по ней не видно, кто уже собран. */
{
  const sh = { from: '2026-11-01', to: '2026-11-07' };
  eq('вносим сегодня — числится с сегодня', API._campGrpFrom(sh), '2026-09-11');
  // ⚠️ смена уже прошла: «сегодня» позже конца, Alfa такой период не примет
  eq('прошедшая смена — с её начала', API._campGrpFrom({ from: '2026-05-01', to: '2026-05-07' }), '2026-05-01');
  eq('последний день смены ещё можно', API._campGrpFrom({ from: '2026-09-01', to: '2026-09-11' }), '2026-09-11');
  eq('дат у смены нет — сегодня', API._campGrpFrom({}), '2026-09-11');
  eq('смены нет вовсе', API._campGrpFrom(null), '2026-09-11');

  /* ⚠️ Абонемент так растягивать НЕЛЬЗЯ: он оплачен за дни смены. Проверяем прямо по коду
     переноса, что дату внесения он не подхватил. */
  const src2 = (html.match(/\nasync function campToGroup\(id\)\{[\s\S]*?\n\}/m) || [''])[0];
  check('в группу шлём дату внесения', src2.indexOf('b_date:_campGrpFrom(sh)') > 0, src2.slice(0, 900));
  check('в абонементе остаются даты смены', src2.indexOf('bDate:sh.from, eDate:sh.to') > 0, src2);
  /* Жанна: «шаблон абонемента в Альфе делай детям раздельными, а не базовыми». У базового
     лагерные уроки падают в общий баланс и списываются с клубного абонемента на учебный год. */
  // ⚠️ Ищем именно ВЫЗОВ, а не слово: рядом лежит комментарий со словами «separate:true»,
  //    и проверка по голой подстроке проходила бы даже с базовым абонементом в коде.
  check('абонемент выдаём раздельным', src2.indexOf('separate:true, note:') > 0, src2);
  check('базовым больше не выдаём', src2.indexOf('separate:false') < 0, src2);
  // и оба периода названы в вопросе, иначе человек жмёт «да» вслепую
  check('в вопросе назван период в группе', src2.indexOf('• в группе: с ') > 0, src2.slice(0, 900));
  check('и период абонемента отдельно', src2.indexOf('• абонемент действует: ') > 0, src2.slice(0, 900));
}

/* ================= ПЕРЕНОС РЕБЁНКА В ALFACRM: ЧТО МЕШАЕТ =================
   Просьба Жанны 09.09.2026: «переносить точечно детей в Альфу, в филиал Каникулы и группу
   Хогвартс 27, заносить их в группу и создавать абонемент на 950».
   ⚠️ Это ЖИВАЯ запись в CRM, и промах здесь — ребёнок в чужой группе с чужим абонементом.
   Поэтому кнопка не просто «не работает»: она заранее говорит, чего не хватает. Проверяем, что
   ни одна из четырёх нехваток не пропускается молча. */
{
  const W = API._campWhyNoTransfer, IN = API._campInGroup;
  /* ⚠️ Alfa требует обязательно и предмет, и тип урока — в рабочей смене они заданы. */
  const sh = () => ({ id: 's1', name: 'Хогвартс 27', from: '2026-11-01', to: '2026-11-07',
                      alfaGroupId: 77, alfaTariffId: 5, alfaSubjId: 42, alfaLtId: 3 });
  const kid = () => ({ id: 'k1', fio: 'Ерш Арсений', alfaId: 502 });

  eq('всё на месте — переносим', W(sh(), kid()), '');

  // ⚠️ ребёнок ещё не заведён в Alfa: заносить в группу некого
  const noId = kid(); noId.alfaId = null;
  check('без клиента Alfa не переносим', W(sh(), noId).indexOf('ещё нет в Alfa') >= 0, W(sh(), noId));

  // ⚠️ группа не выбрана — иначе ребёнок уехал бы в группу с id 0
  const noG = sh(); noG.alfaGroupId = 0;
  check('без группы не переносим', W(noG, kid()).indexOf('не выбрана группа') >= 0, W(noG, kid()));

  // ⚠️ шаблон не выбран — абонемент не из чего сделать
  const noT = sh(); noT.alfaTariffId = 0;
  check('без шаблона не переносим', W(noT, kid()).indexOf('не выбран шаблон') >= 0, W(noT, kid()));

  // ⚠️ без дат смены период абонемента взять неоткуда
  const noD = sh(); noD.to = '';
  check('без дат смены не переносим', W(noD, kid()).indexOf('не заданы даты') >= 0, W(noD, kid()));
  /* ⚠️ Оба поля Alfa требует обязательно. Раньше о них узнавали ТОЛЬКО после того, как ребёнок
     уже уехал в группу: два разных отказа подряд и два похода в настройки. */
  const noS = sh(); noS.alfaSubjId = 0;
  check('без предмета не переносим', W(noS, kid()).indexOf('не определён предмет') >= 0, W(noS, kid()));
  const noL = sh(); noL.alfaLtId = 0;
  check('без типа урока не переносим', W(noL, kid()).indexOf('не выбран тип урока') >= 0, W(noL, kid()));
  const noD2 = sh(); noD2.from = '';
  check('и без даты начала тоже', W(noD2, kid()).indexOf('не заданы даты') >= 0);

  // смены нет вовсе / строки нет — тоже причина, а не тихий отказ
  check('без смены', W(null, kid()).length > 0);
  check('без строки', W(sh(), null).length > 0);

  /* --- отметка «уже перенесён» --- */
  const done = kid(); done.alfaGrp = '77';
  check('перенесённый опознан', IN(sh(), done) === true);
  // ⚠️ отметка привязана к КОНКРЕТНОЙ группе: сменили группу в настройках — перенос нужен заново
  const other = sh(); other.alfaGroupId = 88;
  check('в другой группе отметка не считается', IN(other, done) === false);
  check('без отметки — не перенесён', IN(sh(), kid()) === false);
  check('пустая отметка не считается', IN(sh(), Object.assign(kid(), { alfaGrp: '' })) === false);
  check('без смены не считается', IN(null, done) === false);

  /* --- цена шаблона: Alfa шлёт строкой и бывает с запятой --- */
  const PR = API._campTarPrice;
  eq('число', PR({ price: 950 }), 950);
  eq('строкой', PR({ price: '950' }), 950);
  eq('с запятой', PR({ price: '950,50' }), 950.5);
  eq('с точкой', PR({ price: '950.50' }), 950.5);
  eq('пусто — нет цены', PR({ price: '' }), null);
  eq('нет поля', PR({}), null);
  eq('не число', PR({ price: 'бесплатно' }), null);
  eq('нуль — это цена, а не пустота', PR({ price: 0 }), 0);

  /* --- подпись шаблона: по ней Жанна узнаёт нужный («на 950») --- */
  const L = API._campTarLabel;
  eq('имя, цена и занятия', L({ id: 5, name: 'Лагерь', price: '950', lesson_count: 6 }),
     'Лагерь · 950 р. · 6 зан.');
  eq('без цены', L({ id: 5, name: 'Лагерь' }), 'Лагерь');
  eq('без имени — по id', L({ id: 7 }), 'шаблон 7');
}

/* ================= ПРЕДМЕТ ДЛЯ АБОНЕМЕНТА =================
   ⚠️ Живой случай 09.09.2026: Жанна нажала перенос и получила «Участие продлено, но абонемент не
   выдан: у шаблона не заданы предметы». Её шаблон «950» — поурочный, 5 уроков по 190, предметы
   в нём действительно пустые.
   Правильный источник — не шаблон, а ГРУППА: предмет, за который списывается занятие, знает
   именно она, и абонемент обязан покрывать его. Шаблон остаётся запасным путём (сборные группы
   без предмета). Нет ни там, ни там — не выдумываем. */
{
  const keepG = ctx._campAlfaGroups, keepT = ctx._campAlfaTariffs;
  const SJ = API._campSubjIds;
  const sh = { alfaGroupId: 77, alfaTariffId: 5 };

  // 1. у группы предмет есть — берём его, шаблон не спрашиваем
  ctx._campAlfaGroups = [{ id: 77, name: 'Хогвартс 27', subject_ids: [11] }];
  ctx._campAlfaTariffs = [{ id: 5, name: '950', subject_ids: [99] }];
  eq('предмет берём у группы', SJ(sh).ids.join(','), '11');
  eq('и говорим, откуда', SJ(sh).from, 'группы');

  // 2. ⚠️ ровно случай Жанны: у шаблона пусто, у группы есть — перенос обязан пройти
  ctx._campAlfaTariffs = [{ id: 5, name: '950', subject_ids: [] }];
  eq('пустой шаблон не мешает', SJ(sh).ids.join(','), '11');

  // 3. у группы пусто (сборная), у шаблона есть — запасной путь
  ctx._campAlfaGroups = [{ id: 77, name: 'Сборная', subject_ids: [] }];
  ctx._campAlfaTariffs = [{ id: 5, name: '950', subject_ids: [99, 100] }];
  eq('тогда берём у шаблона', SJ(sh).ids.join(','), '99,100');
  eq('и это видно', SJ(sh).from, 'шаблона');

  // 4. нет нигде — не выдумываем
  ctx._campAlfaTariffs = [{ id: 5, name: '950', subject_ids: [] }];
  eq('предмета нет нигде', SJ(sh).ids.length, 0);
  eq('и источника нет', SJ(sh).from, '');

  // 5. мусор в списках не должен пролезать в запрос к Alfa
  ctx._campAlfaGroups = [{ id: 77, name: 'Х', subject_ids: [0, null, 11, '12'] }];
  eq('нули и пустышки отброшены', SJ(sh).ids.join(','), '11,12');

  // 6. списки ещё не загружены / смена без выбора — тихо ноль, а не падение
  ctx._campAlfaGroups = null; ctx._campAlfaTariffs = null;
  eq('без загруженных списков', SJ(sh).ids.length, 0);
  ctx._campAlfaGroups = [{ id: 77, subject_ids: [11] }];
  eq('смена без группы', SJ({ alfaGroupId: 0, alfaTariffId: 0 }).ids.length, 0);
  eq('смены нет вовсе', SJ(null).ids.length, 0);

  ctx._campAlfaGroups = keepG; ctx._campAlfaTariffs = keepT;
}


/* ⚠️ Предмет, выбранный руками, главнее всего: у Жанны его не оказалось НИ у группы
   «Хогвартс 27», НИ у шаблона «950» — в лагере занятия к предмету не привязаны так, как в
   учебных группах. Отправлять её править Alfa ради одного поля не дело: выбор в настройках
   смены решает вопрос на месте. */
{
  const keepG = ctx._campAlfaGroups, keepT = ctx._campAlfaTariffs;
  const SJ = API._campSubjIds;
  ctx._campAlfaGroups = [{ id: 77, name: 'Хогвартс 27', subject_ids: [] }];
  ctx._campAlfaTariffs = [{ id: 5, name: '950', subject_ids: [] }];
  // ровно случай со скриншота: пусто и там, и там
  eq('без выбора предмета нет', SJ({ alfaGroupId: 77, alfaTariffId: 5 }).ids.length, 0);
  // выбрали руками — работает, ничего в Alfa править не надо
  const s = { alfaGroupId: 77, alfaTariffId: 5, alfaSubjId: 42 };
  eq('выбранный предмет взят', SJ(s).ids.join(','), '42');
  eq('и источник назван', SJ(s).from, 'настроек смены');
  // ⚠️ выбор главнее группы и шаблона: сменили в настройках — едет он, а не старое
  ctx._campAlfaGroups = [{ id: 77, name: 'Х', subject_ids: [11] }];
  eq('выбор главнее группы', SJ(s).ids.join(','), '42');
  eq('а без выбора — снова группа', SJ({ alfaGroupId: 77, alfaTariffId: 5 }).from, 'группы');
  eq('ноль в выборе — это «не выбрано»', SJ({ alfaGroupId: 77, alfaTariffId: 5, alfaSubjId: 0 }).from, 'группы');
  ctx._campAlfaGroups = keepG; ctx._campAlfaTariffs = keepT;
}


/* ⚠️ Тип урока Alfa требует ОТДЕЛЬНО от предмета: отказ «lesson_type_ids: Необходимо заполнить
   „Типы уроков"». В учебном переносе он берётся из настроек публикации — для лагеря даём
   свой выбор, но публикацию оставляем запасным путём: чаще всего тип один и тот же. */
{
  const LT = API._campLtIds, keepPub = ctx._pubCfg;
  ctx._pubCfg = () => ({});
  eq('ничего не задано — типа нет', LT({}).ids.length, 0);
  eq('и источника нет', LT({}).from, '');
  eq('выбранный в смене', LT({ alfaLtId: 3 }).ids.join(','), '3');
  eq('и источник назван', LT({ alfaLtId: 3 }).from, 'настроек смены');
  // запасной путь: настройки публикации
  ctx._pubCfg = () => ({ lessonTypeId: 9 });
  eq('берём из публикации', LT({}).ids.join(','), '9');
  eq('и это видно', LT({}).from, 'настроек публикации');
  // ⚠️ выбор в смене главнее публикации: лагерь и учебные занятия — разные типы
  eq('смена главнее публикации', LT({ alfaLtId: 3 }).ids.join(','), '3');
  eq('ноль — это «не выбрано»', LT({ alfaLtId: 0 }).from, 'настроек публикации');
  // испорченные настройки публикации не должны ронять перенос
  ctx._pubCfg = () => { throw new Error('нет настроек'); };
  eq('публикация упала — просто нет типа', LT({}).ids.length, 0);
  eq('а свой выбор всё равно работает', LT({ alfaLtId: 3 }).ids.join(','), '3');
  ctx._pubCfg = keepPub;
}

console.log(bad ? '\nПРОВАЛЕНО: ' + bad : '\nВсё сошлось');
process.exit(bad ? 1 : 0);
