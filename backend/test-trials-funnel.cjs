// Общая воронка и прогноз по новому набору. Запуск: node backend/test-trials-funnel.cjs
//
// Жанна: «сделай прогноз, чтобы админ знал, кто новый придёт и когда, и ждал его. И статистику
// сверху: вписанные, дошедшие, не дошедшие и кого ещё ждём».
//
// Главное, что проверяется: ребёнок считается ОДИН раз, а не по числу занятий. Тот, кто был
// хоть раз, — «дошёл», даже если потом пропускал. «Не дошёл» — только если занятия были и
// впереди ничего не осталось: пока занятия впереди есть, ребёнок в «ждём», а не в потерях.
const fs = require('fs');
const path = require('path');
const NL = String.fromCharCode(10);
const BS = String.fromCharCode(92);
const Q = String.fromCharCode(39);
const src = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

function grab(name) {
  const i = src.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('не найдена ' + name);
  const j = src.indexOf(NL + '}', i);
  return src.slice(i, j + 2) + NL;
}
let bad = 0;
const t = (n, c, d) => { if (!c) bad++; console.log((c ? 'ok   ' : 'FAIL ') + n + (c || !d ? '' : ': ' + d)); };
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const TODAY = '2026-09-10';
function build(kids, newIds, opts) {
  const o = opts || {};
  const scope = {
    esc,
    S: { children: {} },
    _trFun: { kids, from: '2026-09-01', to: '2026-10-10' },
    _trFunBusy: !!o.busy,
    _trFunProg: o.prog || '',
    _trRefs: { subjects: { 7: 'Арт-студия', 9: 'Пескография' }, teachers: {} },
    _trCards: o.cards || {},
    _trToday: () => TODAY,
    _goalsKidLists: () => ({ newKids: (newIds || []).map(id => (o.noModelNames ? { alfaId: id } : { alfaId: id, name: 'Ребёнок ' + id })) }),
    trLoadFunnel: () => {},
    // экранирование для inline-обработчика; здесь не проверяется, нужен только вызов
    _jsStr: (x) => String(x).split(BS).join(BS + BS).split(Q).join(BS + Q),
    BS, Q,
    Date, String, Number, Object, Set, Math,
  };
  return new Function(...Object.keys(scope),
    grab('_trNewSet') + grab('_trLesState') + grab('_trFunnel') + grab('_trKidName') + grab('_trArchived') + grab('_trArchBadge') + grab('_trKidLink') + grab('_trListHtml')
    + grab('_trDayLabel') + grab('_trFunnelHtml')
    + '; return {f:_trFunnel(), html:_trFunnelHtml(), st:_trLesState};'
  )(...Object.values(scope));
}
const les = (date, done, sum, attend, subjectId) =>
  ({ date, done, sum, attend, subjectId: subjectId || 7, from: '17:30', to: '18:30', teacherIds: [] });

console.log('--- 1. состояние одного занятия ---');
const st = build({}, []).st;
t('впереди — ждём', st(les('2026-09-20', false, null, null), TODAY) === 'ahead');
t('день прошёл, а занятия не было — не состоялось', st(les('2026-09-01', false, null, null), TODAY) === 'missed');
t('списали — пришёл', st(les('2026-09-01', true, 15, true), TODAY) === 'came');
t('0 и отметка — пришёл, абонемент не проставлен', st(les('2026-09-01', true, 0, true), TODAY) === 'came_free');
t('0 и без отметки — не пришёл', st(les('2026-09-01', true, 0, null), TODAY) === 'missed');

console.log('--- 2. ГЛАВНОЕ: ребёнок считается один раз ---');
let r = build({
  1: [les('2026-09-01', true, 15, true), les('2026-09-08', true, 0, null), les('2026-09-20', false)],
  2: [les('2026-09-01', true, 0, null), les('2026-09-08', true, 0, null)],
  3: [les('2026-09-20', false), les('2026-09-27', false)],
  4: [],
}, [1, 2, 3, 4]);
t('вписаны на занятия — 3 (у четвёртого занятий нет)', r.f.enrolled === 3, String(r.f.enrolled));
t('дошёл один — тот, кто был хоть раз', r.f.came === 1, String(r.f.came));
t('не дошёл ни разу — один', r.f.missedAll === 1, String(r.f.missedAll));
t('ждём — один', r.f.waiting === 1, String(r.f.waiting));
t('без занятий — один', r.f.noLessons === 1, String(r.f.noLessons));
t('пропуски дошедшего не переводят его в потери', !r.html.includes('>2</div>') || r.f.came === 1);

console.log('--- 3. ГЛАВНОЕ: пропустил прошедшее занятие — это «не дошёл», даже если впереди есть ещё ---');
// Раньше такой ребёнок попадал в «ждём», потому что корзина определялась будущими занятиями.
// А у детей регулярные занятия до мая, поэтому «не дошли» показывало ноль всегда, и тот,
// кому надо звонить, прятался в спокойной корзине.
r = build({ 5: [les('2026-09-01', true, 0, null), les('2026-09-20', false)] }, [5]);
t('ребёнок в «не дошли»', r.f.missedAll === 1 && r.f.waiting === 0);
t('и отмечено, что занятия впереди у него есть', r.f.missedButAhead === 1);
t('в подписи это видно', r.html.includes('есть занятия впереди'));

// а вот тот, у кого прошедших занятий ещё не было, — честно «ждём»
r = build({ 6: [les('2026-09-20', false), les('2026-09-27', false)] }, [6]);
t('первое занятие впереди — ждём', r.f.waiting === 1 && r.f.missedAll === 0);

console.log('--- 4. прогноз: кого и когда ждём ---');
r = build({
  6: [les('2026-09-12', false)],
  7: [les('2026-09-12', false), les('2026-09-15', false, null, null, 9)],
}, [6, 7]);
t('раздел прогноза есть', r.html.includes('Кого ждём дальше'));
t('день подписан', r.html.includes('12.09'));
t('имя ребёнка показано', r.html.includes('Ребёнок 6'));
t('курс показан', r.html.includes('Арт-студия') && r.html.includes('Пескография'));
t('время показано', r.html.includes('17:30'));

console.log('--- 5. сегодняшний день подписан отдельно ---');
r = build({ 8: [les(TODAY, false)] }, [8]);
t('«сегодня» в подписи дня', r.html.includes('сегодня'));

console.log('--- 6. впереди пусто ---');
r = build({ 9: [les('2026-09-01', true, 15, true)] }, [9]);
t('сказано, что занятий впереди нет', r.html.includes('Впереди занятий'));

console.log('--- 7. предупреждение про непроставленный абонемент ---');
r = build({ 10: [les('2026-09-01', true, 0, true)] }, [10]);
t('дошёл', r.f.came === 1);
t('и отдельно посчитан как без списания', r.f.cameFree === 1);
t('плашка показана', r.html.includes('абонемент не проставили'));

console.log('--- 8. идёт чтение ---');
r = build({ 11: [les('2026-09-12', false)] }, [11], { busy: true, prog: '15/153' });
t('виден прогресс', r.html.includes('15/153'));


console.log('--- 9. списки под цифрами: видно, кто и куда шёл ---');
// Жанна: «дай список тех и куда шли, чтобы я могла понять, верно ли ты собираешь данные»
r = build({
  20: [les('2026-09-01', true, 15, true), les('2026-09-20', false, null, null, 9)],
  21: [les('2026-09-01', true, 0, null)],
  22: [les('2026-09-20', false)],
  23: [],
}, [20, 21, 22, 23]);
t('раскрывашка «Дошли» есть', r.html.includes('Дошли'));
t('раскрывашка «Не дошли» есть', r.html.includes('Не дошли'));
t('раскрывашка «Ждём» есть', r.html.includes('Ждём'));
t('раскрывашка «Без занятий» есть', r.html.includes('Без занятий'));
t('имена детей в списках', r.html.includes('Ребёнок 20') && r.html.includes('Ребёнок 21'));
t('видно, КУДА шёл — курс', r.html.includes('Арт-студия') && r.html.includes('Пескография'));
t('видно дату занятия', r.html.includes('01.09'));
t('видно исход каждого занятия', r.html.includes('пришёл') && r.html.includes('не пришёл'));
t('сумма списания показана', r.html.includes('>15<'));
t('у ребёнка без занятий так и написано', r.html.includes('занятий в расписании нет'));
t('пустая корзина раскрывашку не рисует', !r.html.includes('>0</span>'));


console.log('--- 10. имена — ссылки на карточку ребёнка ---');
// Жанна: «хочу, чтобы эти дети были активными ссылками, чтобы сразу открывалась карточка
// в финмодели: куда он ходит ещё, данные»
r = build({ 30: [les('2026-09-20', false)], 31: [les('2026-09-01', true, 15, true)] }, [30, 31]);
t('в прогнозе имя кликабельно', r.html.includes('trOpenKid('));
t('передаётся id ребёнка', r.html.includes('trOpenKid(') && r.html.includes('30'));
t('переход по ссылке не перезагружает страницу', r.html.includes('return false'));
t('есть подсказка при наведении', r.html.includes('Открыть карточку ребёнка'));
t('в раскрывашках имя тоже ссылка', r.html.split('trOpenKid(').length - 1 >= 2);


console.log('--- 11. архивных помечаем отдельно ---');
// Жанна: «дети, которые есть в „без занятий“ и которые в архиве, нужно помечать что в архиве».
// Архив в Alfa — это removed, а не is_study=0: последнее означает лида, то есть как раз того,
// кого мы ждём.
r = build({ 40: [], 41: [] }, [40, 41],
  { cards: { 40: { name: 'Дворецкий Тимофей', archived: true }, 41: { name: 'Бордовская Ульяна', archived: false } } });
t('пометка «в архиве» есть', r.html.includes('в архиве'));
t('и только одна', r.html.split('>в архиве<').length - 1 === 1);
t('в подписи корзины сказано, сколько их', r.html.includes('из них в архиве 1'));
t('оба ребёнка всё равно в списке', r.html.includes('Ребёнок 40') && r.html.includes('Ребёнок 41'));
// ребёнка нет в модели — имя берём из карточки Alfa, а не показываем «id 43»
r = build({ 43: [] }, [43], { cards: { 43: { name: 'Дворецкий Тимофей', archived: true } }, noModelNames: true });
t('имя подставлено из Alfa', r.html.includes('Дворецкий Тимофей'), r.html.slice(0, 0) || 'нет имени');
t('и он помечен архивным', r.html.includes('в архиве'));
r = build({ 42: [] }, [42], { cards: { 42: { name: 'Кто-то', archived: false } } });
t('без архивных подписи нет', !r.html.includes('из них в архиве'));


console.log('--- 12. прогноз: без повторов и с раскрывашками по дням ---');
// Жанна: «не давай повтор той же группы — сегодня 6 сент вс и ты дал след вс. Незачем, если
// там тот же ребёнок на том же курсе». При регулярном расписании до мая следующая неделя
// ничего нового не сообщает.
r = build({
  50: [les('2026-09-13', false), les('2026-09-20', false), les('2026-09-27', false)],   // один курс, три воскресенья
  51: [les('2026-09-13', false), les('2026-09-14', false, null, null, 9)],              // два РАЗНЫХ курса
}, [50, 51]);
// имя встречается и в списках корзин, поэтому считаем только внутри блока прогноза
const fcOnly = r.html.slice(r.html.indexOf('Кого ждём дальше'));
t('ребёнок на одном курсе показан один раз', fcOnly.split('trOpenKid(' + Q + '50' + Q).length - 1 === 1);
t('на разных курсах — оба занятия', fcOnly.split('trOpenKid(' + Q + '51' + Q).length - 1 === 2);
t('сказано, сколько повторов скрыто', r.html.includes('их 2'));
t('в прогнозе два дня, а не три', fcOnly.split('<details').length - 1 === 2);
t('первый день раскрыт', fcOnly.includes('<details open'));
t('остальные свёрнуты', fcOnly.split('<details open').length - 1 === 1);
t('в заголовке дня видно количество', r.html.includes('— 2') || r.html.includes('— 1'));

r = build({ 52: [les('2026-09-13', false)] }, [52]);
t('без повторов подписи про них нет', !r.html.includes('Повторы того же ребёнка'));

if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
console.log(NL + 'всё сошлось');
