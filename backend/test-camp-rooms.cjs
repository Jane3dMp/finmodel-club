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
const MULTI = ['_campSeedLayout', '_campCap', '_campBeds', '_campSplitFloor2', '_campWhyNoTransfer', '_campTarLabel'];
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
const ctx = { _campNewIdSeq: 0, _gm: n => String(Math.round(+n||0)) };
/* _campBlockHtml рисует карточку блока — вырезаем отдельно, у него свой набор заглушек. */
const mb = html.match(/\nfunction _campBlockHtml\([^)]*\)\s*\{[\s\S]*?\n\}/m);
if (!mb) { console.log('не найдено в index.html: _campBlockHtml'); process.exit(1); }
const blockSrc = mb[0];
const API = new Function('ctx', 'with (ctx) { ' + src + ' return {_campSeedLayout,_campCap,_campBeds,_campSplitFloor2,_campWhyNoTransfer,_campInGroup,_campTarPrice,_campTarLabel}; }')(ctx);

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
    CAMP_ST: { yes: { t: 'подтвердил', c: 'g', ic: '🟢' }, maybe: { t: 'думает', c: 'y', ic: '🟡' }, no: { t: 'отказ', c: 'r', ic: '🔴' } },
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
        /<span class="cmp-bn">[^<]*Мельников Илья<i class="cmp-kid-a">8,7<\/i><\/span>/.test(h),
        (h.match(/<span class="cmp-bn">[\s\S]{0,140}/) || [''])[0]);
  check('свободные места подписаны', h.indexOf('свободно') > 0);
  check('счётчик блока', h.indexOf('1/3') > 0, (h.match(/cmp-cap">[^<]*/) || [''])[0]);

  // без даты рождения возраста нет — и пустого «<i>» тоже быть не должно
  const h2 = B._campBlockHtml(sh, blk, { 'b1|r1|0': { id: 'k2', fio: 'Без Даты', status: 'yes', dob: '' } });
  check('без даты рождения возраст не рисуем', h2.indexOf('cmp-kid-a') < 0,
        (h2.match(/<span class="cmp-bn">[\s\S]{0,120}/) || [''])[0]);
  check('но имя на месте', h2.indexOf('Без Даты') > 0);

  /* ⚠️ Блок вожатых — отдельная история: там не дети, а поля для ФИО, и ни возраста, ни
     выбора пола быть не должно. Именно эти блоки не участвуют в расселении. */
  const cf = { id: 'c1', name: 'Комфорт 1', floor: '2 этаж', staff: true, rooms: [{ id: 'r1', name: 'комната', beds: 2 }] };
  const h3 = B._campBlockHtml({ sex: {}, staff: { 'c1|r1|0': 'Иванова А.' } }, cf, {});
  check('у вожатых поле ФИО', h3.indexOf('вожат') > 0, h3.slice(0, 260));
  check('и нет выбора пола', h3.indexOf('— пол не задан —') < 0);
  check('и подпись «вожатые»', h3.indexOf('вожатые · 2') > 0, (h3.match(/cmp-cap">[^<]*/) || [''])[0]);
}

/* ================= ПЕРЕНОС РЕБЁНКА В ALFACRM: ЧТО МЕШАЕТ =================
   Просьба Жанны 09.09.2026: «переносить точечно детей в Альфу, в филиал Каникулы и группу
   Хогвартс 27, заносить их в группу и создавать абонемент на 950».
   ⚠️ Это ЖИВАЯ запись в CRM, и промах здесь — ребёнок в чужой группе с чужим абонементом.
   Поэтому кнопка не просто «не работает»: она заранее говорит, чего не хватает. Проверяем, что
   ни одна из четырёх нехваток не пропускается молча. */
{
  const W = API._campWhyNoTransfer, IN = API._campInGroup;
  const sh = () => ({ id: 's1', name: 'Хогвартс 27', from: '2026-11-01', to: '2026-11-07',
                      alfaGroupId: 77, alfaTariffId: 5 });
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

console.log(bad ? '\nПРОВАЛЕНО: ' + bad : '\nВсё сошлось');
process.exit(bad ? 1 : 0);
