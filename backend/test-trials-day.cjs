// Вкладка «Пробные» — рендер дня. Запуск: node backend/test-trials-day.cjs
//
// Пробный опознаётся по СУММЕ списания (0 или 15): шаблон абонемента часто ставят прямо на
// занятии. Но одной суммы мало — у ПОСТОЯННОГО ребёнка без абонемента спишется 0 ровно так же,
// а если он ещё и пропустил занятие, то выглядит как непришедший пробник. Первый живой прогон
// дал 78 «пробных» за день, из них 50 «не пришёл».
//
// Жанна попросила упростить: следить за детьми из «нового набора». Прежний режим (кто не ходил
// на этот курс) оставлен — доработаем позже.
//
// Вечером состояний три, а не два: у ребёнка без абонемента 0 спишется и когда он пришёл,
// отличить можно только по отметке присутствия. «Пришёл, а списания нет» — потеря денег.
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
    _trMode: o.mode || 'new',
    _goalsKidLists: o.newKids === null ? undefined : () => ({ newKids: o.newKids || [] }),
    trSetMode: () => {}, trShiftDay: () => {}, trSetDate: () => {}, trLoad: () => {},
    // общая воронка живёт своей жизнью и проверяется в test-trials-funnel.cjs
    _trFunnelHtml: () => (o.funnel || ''),
    _pubErrHtml: (e) => '<div class="callout">ошибка: ' + esc(String((e && e.message) || e)) + '</div>',
    document: { getElementById: (id) => (id === 'trialsBody' ? { set innerHTML(v) { out = v; } } : null) },
    // экранирование для inline-обработчика; здесь не проверяется, нужен только вызов
    _jsStr: (x) => String(x).split(BS).join(BS + BS).split(Q).join(BS + Q),
    BS, Q,
    Date, String, Math, Number, Object, Set,
  };
  new Function(...Object.keys(scope),
    stateSrc + grab('_trToday') + grab('_trIsNew') + grab('_trNewSet') + grab('_trKidName') + grab('_trKidLink') + grab('renderTrials') + '; renderTrials();'
  )(...Object.values(scope));
  return out;
}

const kid = (id, name, sum, state, trialSum) => ({ customerId: id, name, sum, state, cttId: 0,
  trialSum: (trialSum === undefined ? (sum === 0 || sum === 15) : trialSum) });
const lesson = (subjectId, kids, done) => ({
  id: 1, done: !!done, from: '10:00', to: '11:30', subjectId,
  subject: '3D Blender и Unity', teacher: 'Винтилов Сергей', seats: 8, kids,
});
const day = (lessons, extra) => Object.assign({
  date: '2026-09-05', prices: [0, 15], lessonsScanned: 29, counts: {}, lessons,
}, extra || {});
const NEW = [{ alfaId: 1 }, { alfaId: 2 }];   // «новый набор» — дети 1 и 2

console.log('--- 1. ГЛАВНОЕ: показываем только новый набор ---');
let h = render(day([lesson(7, [
  kid(1, 'Крючкова Ева', 15, 'came'),
  kid(9, 'Белецкая Анна', 0, 'missed'),
], true)]), { newKids: NEW });
t('ребёнок из набора показан', h.includes('Крючкова Ева'));
t('чужой скрыт', !h.includes('Белецкая'));
t('в счёт попал только свой', h.includes('>1</div>'));
t('подпись плитки про набор', h.includes('из нового набора'));
t('сказано, сколько скрыто', h.includes('Скрыто 1'));

console.log('--- 2. переключение режимов ---');
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came'), kid(9, 'Аня', 0, 'missed')], true)]),
  { newKids: NEW, mode: 'all' });
t('режим «все» показывает обоих', h.includes('Ева') && h.includes('Аня'));
t('и считает обоих', h.includes('>2</div>'));
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came'), kid(9, 'Аня', 0, 'missed')], true)]),
  { newKids: NEW, mode: 'course', hist: { 1: { subjects: {} }, 9: { subjects: { 7: 12 } } } });
t('режим «не ходил на курс» работает как раньше', h.includes('Ева') && !h.includes('Аня'));
t('все три режима есть в переключателе', h.includes('Только новый набор') && h.includes('Не ходил на этот курс') && h.includes('Все кандидаты'));

console.log('--- 3. переключение дней ---');
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came')], true)]), { newKids: NEW });
t('есть стрелки', h.includes('trShiftDay(-1)') && h.includes('trShiftDay(1)'));
t('есть кнопка «сегодня» для прошлой даты', h.includes('сегодня'));
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came')], true)], { fromStore: true }), { newKids: NEW });
t('видно, что день взят из памяти', h.includes('из памяти'));
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came')], true)], { fromStore: false }), { newKids: NEW });
t('свежий день так не помечен', !h.includes('из памяти'));

console.log('--- 4. три состояния вечером ---');
h = render(day([lesson(7, [
  kid(1, 'Ева', 15, 'came'), kid(2, 'Степан', 0, 'came_free'),
], true)]), { newKids: NEW });
t('пришёл и списано', h.includes('пришёл, списано'));
t('пришёл, абонемент не проставлен', h.includes('абонемент не проставлен'));
t('про потерю денег сказано отдельно', h.includes('прямая потеря'));

console.log('--- 5. список нового набора недоступен ---');
h = render(day([lesson(7, [kid(1, 'Ева', 15, 'came')], true)]), { newKids: null });
t('объяснено, что делать', h.includes('Откройте её один раз'));
t('и предложен запасной режим', h.includes('не ходил на этот курс'));

console.log('--- 6. пусто / ошибка / загрузка ---');
h = render(day([]), { newKids: NEW });
t('пробных нет вовсе', h.includes('пробных не нашлось'));
h = render(day([lesson(7, [kid(9, 'Аня', 0, 'missed')], true)]), { newKids: NEW });
t('никого из набора не нашлось', h.includes('никого не нашлось'));
h = render(null, { err: new Error('Хостинг не пропустил запрос'), newKids: NEW });
t('ошибка показана', h.includes('Хостинг не пропустил'));
h = render(null, { busy: true, newKids: NEW });
t('видно, что идёт чтение', h.includes('Читаю занятия дня'));


console.log('--- 7. режим набора не отсеивает по сумме ---');
// Жанна заметила расхождение: дневной список показывал 4 ребёнка, а прогноз на тот же день —
// 27. Дневной фильтровал по сумме списания (0 или 15) и только потом по набору, а новый
// ребёнок с нормальным абонементом (списалось 36) — ровно тот, кого надо отслеживать.
h = render(day([lesson(7, [
  kid(1, 'Обычный абонемент', 36, 'came'),
  kid(2, 'Пробная сумма', 15, 'came'),
  kid(9, 'Не из набора', 36, 'came'),
], true)]), { newKids: NEW });
t('ребёнок набора с обычной суммой ПОКАЗАН', h.includes('Обычный абонемент'));
t('ребёнок набора с пробной суммой показан', h.includes('Пробная сумма'));
t('чужой скрыт', !h.includes('Не из набора'));
t('в счёте оба своих', h.includes('>2</div>'));
t('подпись объясняет, что показываются все занятия', h.includes('все их занятия за день'));

h = render(day([lesson(7, [
  kid(1, 'Обычный абонемент', 36, 'came'),
  kid(2, 'Пробная сумма', 15, 'came'),
], true)]), { newKids: NEW, mode: 'all' });
t('режим «все кандидаты» по-прежнему по сумме', !h.includes('Обычный абонемент') && h.includes('Пробная сумма'));
t('и подпись про сумму', h.includes('по сумме списания'));

if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
console.log(NL + 'всё сошлось');
