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
    _trToday: () => TODAY,
    _goalsKidLists: () => ({ newKids: (newIds || []).map(id => ({ alfaId: id, name: 'Ребёнок ' + id })) }),
    trLoadFunnel: () => {},
    Date, String, Number, Object, Set, Math,
  };
  return new Function(...Object.keys(scope),
    grab('_trNewSet') + grab('_trLesState') + grab('_trFunnel') + grab('_trKidName')
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

console.log('--- 3. пока занятия впереди — не записываем в потери ---');
r = build({ 5: [les('2026-09-01', true, 0, null), les('2026-09-20', false)] }, [5]);
t('ребёнок в «ждём», а не в «не дошли»', r.f.waiting === 1 && r.f.missedAll === 0);

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

if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
console.log(NL + 'всё сошлось');
