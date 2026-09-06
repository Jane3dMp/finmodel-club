// Вкладка «Пробные» — рендер дня. Запуск: node backend/test-trials-day.cjs
//
// Правило Жанны: пробный опознаётся по СУММЕ списания (0 или 15), потому что шаблон абонемента
// часто ставят прямо на занятии. Но одной суммы мало: у ПОСТОЯННОГО ребёнка без абонемента
// спишется 0 ровно так же, а если он ещё и пропустил занятие — выглядит как непришедший
// пробник. Поэтому отсеиваем тех, кто на ЭТОТ курс уже ходил.
//
// И вечером состояний три, а не два: у ребёнка без абонемента 0 спишется и когда он пришёл,
// отличить можно только по отметке присутствия. «Пришёл, а списания нет» — потеря денег.
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
const iState = src.indexOf('const TR_STATE={');
if (iState < 0) throw new Error('не найден TR_STATE');
const stateSrc = src.slice(iState, src.indexOf('};', iState) + 2) + NL;

let bad = 0;
const t = (n, c, d) => { if (!c) bad++; console.log((c ? 'ok   ' : 'FAIL ') + n + (c || !d ? '' : ': ' + d)); };

const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const _gm = n => String(Math.round(+n || 0));

function render(data, opts) {
  const o = opts || {};
  let out = '';
  const scope = {
    esc, _gm,
    _trDate: (data && data.date) || '2026-09-05',
    _trData: data,
    _trBusy: !!o.busy,
    _trErr: o.err || null,
    _trHist: o.hist === undefined ? null : o.hist,
    _trHistBusy: !!o.histBusy,
    _trShowAll: !!o.showAll,
    trToggleAll: () => {},
    _pubErrHtml: (e) => '<div class="callout">ошибка: ' + esc(String((e && e.message) || e)) + '</div>',
    document: { getElementById: (id) => (id === 'trialsBody' ? { set innerHTML(v) { out = v; } } : null) },
    Date, String, Math, Number, Object, Set,
  };
  new Function(...Object.keys(scope),
    stateSrc + grab('_trToday') + grab('_trIsNew') + grab('renderTrials') + '; renderTrials();'
  )(...Object.values(scope));
  return out;
}

const kid = (id, name, sum, state) => ({ customerId: id, name, sum, state, cttId: 0 });
const lesson = (subjectId, kids, done) => ({
  id: 1, done: !!done, from: '10:00', to: '11:30', subjectId,
  subject: '3D Blender и Unity', teacher: 'Винтилов Сергей', seats: 8, kids,
});
const day = (lessons) => ({
  date: '2026-09-05', prices: [0, 15], lessonsScanned: 29,
  counts: {}, lessons,
});

console.log('--- 1. утро: занятие ещё не проведено ---');
let h = render(day([lesson(7, [kid(1, 'Крючкова Ева', 15, 'waiting'), kid(2, 'Серова Анна', 0, 'waiting')], false)]),
  { hist: { 1: { subjects: {}, total: 0 }, 2: { subjects: {}, total: 0 } } });
t('видно, кого ждём', h.includes('ждём'));
t('оба ребёнка в списке', h.includes('Крючкова Ева') && h.includes('Серова Анна'));
t('пробных за день — 2', h.includes('>2</div>'));
t('помечено, что занятие ещё не проведено', h.includes('ещё не проведено'));
t('время и педагог показаны', h.includes('10:00') && h.includes('Винтилов Сергей'));

console.log('--- 2. вечер: три состояния ---');
h = render(day([lesson(7, [
  kid(1, 'Крючкова Ева', 15, 'came'),
  kid(2, 'Марочков Степан', 0, 'came_free'),
  kid(3, 'Серова Анна', 0, 'missed'),
], true)]), { hist: { 1: { subjects: {} }, 2: { subjects: {} }, 3: { subjects: {} } } });
t('пришёл и списано', h.includes('пришёл, списано'));
t('пришёл, но абонемент не проставлен', h.includes('абонемент не проставлен'));
t('не пришёл', h.includes('не пришёл'));
t('про потерю денег сказано отдельно', h.includes('прямая потеря'));
t('и названо число таких детей', h.includes('абонемент не проставлен: 1'));

console.log('--- 3. ГЛАВНОЕ: кто уже ходил на этот курс — не пробник ---');
// Белецкая ходила на этот же курс (предмет 7) — её быть не должно
h = render(day([lesson(7, [
  kid(1, 'Крючкова Ева', 15, 'came'),
  kid(2, 'Белецкая Анна', 0, 'missed'),
], true)]), { hist: { 1: { subjects: {} }, 2: { subjects: { 7: 12 } } } });
t('постоянный ребёнок скрыт', !h.includes('Белецкая'));
t('настоящий пробник остался', h.includes('Крючкова Ева'));
t('в счёт попал только он', h.includes('>1</div>'));
t('сказано, сколько скрыто', h.includes('Скрыто 1'));
t('есть кнопка «показать всех»', h.includes('показать всех'));

console.log('--- 4. ходил на ДРУГОЙ курс — всё равно пробник этого ---');
h = render(day([lesson(7, [kid(2, 'Белецкая Анна', 0, 'missed')], true)]),
  { hist: { 2: { subjects: { 99: 30 } } } });
t('ребёнок остался в списке', h.includes('Белецкая'));
t('и посчитан', h.includes('>1</div>'));

console.log('--- 5. «показать всех» возвращает скрытых с пометкой ---');
h = render(day([lesson(7, [
  kid(1, 'Крючкова Ева', 15, 'came'),
  kid(2, 'Белецкая Анна', 0, 'missed'),
], true)]), { hist: { 1: { subjects: {} }, 2: { subjects: { 7: 12 } } }, showAll: true });
t('скрытый показан', h.includes('Белецкая'));
t('и помечен', h.includes('уже ходил на этот курс'));
t('но в счёт не попал', h.includes('>1</div>'));

console.log('--- 6. пока история не пришла — не судим и говорим об этом ---');
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came'), kid(2, 'Аня', 0, 'missed')], true)]),
  { hist: {}, histBusy: true });
t('оба показаны', h.includes('Ева') && h.includes('Аня'));
t('счёт назван предварительным', h.includes('предварительный'));

console.log('--- 7. история не загрузилась — предупреждаем честно ---');
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came')], true)]), { hist: null });
t('сказано, что отсев не сработал', h.includes('не отсеяны'));

console.log('--- 8. все кандидаты оказались постоянными ---');
h = render(day([lesson(7, [kid(2, 'Белецкая Анна', 0, 'missed')], true)]),
  { hist: { 2: { subjects: { 7: 12 } } } });
t('список пуст и объяснён', h.includes('настоящих пробных не нашлось'));
t('счётчик — ноль', h.includes('>0</div>'));

console.log('--- 9. пробных нет вовсе / ошибка / загрузка ---');
h = render({ date: '2026-09-05', prices: [0, 15], lessonsScanned: 29, counts: {}, lessons: [] });
t('сказано прямо', h.includes('пробных не нашлось'));
t('видно, сколько занятий просмотрено', h.includes('29'));
h = render(null, { err: new Error('Хостинг не пропустил запрос') });
t('ошибка показана', h.includes('Хостинг не пропустил'));
h = render(null, { busy: true });
t('видно, что идёт чтение', h.includes('Читаю занятия дня'));

if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
console.log(NL + 'всё сошлось');
