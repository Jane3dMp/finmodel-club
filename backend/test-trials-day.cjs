// Вкладка «Пробные» — рендер дня. Запуск: node backend/test-trials-day.cjs
//
// Правило Жанны: пробный опознаётся по СУММЕ списания (0 или 15), потому что шаблон абонемента
// часто ставят прямо на занятии. Ключевое различие вечером — три состояния, а не два:
// у ребёнка БЕЗ абонемента спишется 0 и в том случае, если он пришёл, и отличить его от
// непришедшего можно только по отметке присутствия. «Пришёл, а списания нет» — потеря денег,
// и это надо показывать отдельно, а не прятать в «пришёл».
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
const jState = src.indexOf('};', iState);
if (iState < 0) throw new Error('не найден TR_STATE');
const stateSrc = src.slice(iState, jState + 2) + NL;

let bad = 0;
const t = (n, c, d) => { if (!c) bad++; console.log((c ? 'ok   ' : 'FAIL ') + n + (c || !d ? '' : ': ' + d)); };

const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const _gm = n => String(Math.round(+n || 0));

function render(data, opts) {
  const o = opts || {};
  let out = '';
  const scope = {
    esc, _gm,
    _trDate: (data && data.date) || '2026-09-06',
    _trData: data,
    _trBusy: !!o.busy,
    _trErr: o.err || null,
    _pubErrHtml: (e) => '<div class="callout">ошибка: ' + esc(String(e && e.message || e)) + '</div>',
    document: { getElementById: (id) => (id === 'trialsBody' ? { set innerHTML(v) { out = v; } } : null) },
    Date, String, Math, Number,
  };
  new Function(...Object.keys(scope), stateSrc + grab('_trToday') + grab('renderTrials') + '; renderTrials();')(...Object.values(scope));
  return out;
}

const kid = (name, sum, state) => ({ customerId: 1, name, sum, state, cttId: 0 });
const day = (kids, done) => ({
  date: '2026-09-06', prices: [0, 15], lessonsScanned: 29,
  counts: kids.reduce((a, k) => { a[k.state] = (a[k.state] || 0) + 1; return a; },
    { waiting: 0, came: 0, came_free: 0, missed: 0 }),
  lessons: [{ id: 1, done: !!done, from: '17:30', to: '18:30', subject: '7 навыков уверенных детей',
              teacher: 'Печковская Юлия', seats: 9, kids }],
});

console.log('--- 1. утро: занятие ещё не проведено ---');
let h = render(day([kid('Крючкова Ева', 15, 'waiting'), kid('Серова Анна', 0, 'waiting')], false));
t('видно, кого ждём', h.includes('ждём'));
t('оба ребёнка в списке', h.includes('Крючкова Ева') && h.includes('Серова Анна'));
t('всего пробных за день — 2', h.includes('>2</div>'));
t('помечено, что занятие ещё не проведено', h.includes('ещё не проведено'));
t('время и педагог показаны', h.includes('17:30') && h.includes('Печковская Юлия'));

console.log('--- 2. вечер: три состояния, а не два ---');
h = render(day([
  kid('Крючкова Ева', 15, 'came'),
  kid('Марочков Степан', 0, 'came_free'),
  kid('Серова Анна', 0, 'missed'),
], true));
t('пришёл и списано', h.includes('пришёл, списано'));
t('пришёл, но абонемент не проставлен', h.includes('абонемент не проставлен'));
t('не пришёл', h.includes('не пришёл'));
t('про потерю денег сказано отдельной плашкой', h.includes('прямая потеря'));
t('и названо число таких детей', h.includes('абонемент не проставлен: 1'));

console.log('--- 3. без «пришёл бесплатно» плашки нет ---');
h = render(day([kid('Крючкова Ева', 15, 'came'), kid('Серова Анна', 0, 'missed')], true));
t('лишнего предупреждения нет', !h.includes('прямая потеря'));

console.log('--- 4. пробных за день не нашлось ---');
h = render({ date: '2026-09-06', prices: [0, 15], lessonsScanned: 29, counts: {}, lessons: [] });
t('сказано прямо', h.includes('пробных не нашлось'));
t('видно, сколько занятий просмотрено', h.includes('29'));
t('подсказано, что делать, если сумма другая', h.includes('другой суммой'));

console.log('--- 5. ошибка и загрузка ---');
h = render(null, { err: new Error('Хостинг не пропустил запрос') });
t('ошибка показана', h.includes('Хостинг не пропустил'));
h = render(null, { busy: true });
t('видно, что идёт чтение', h.includes('Читаю занятия дня'));
h = render(null, {});
t('без данных — приглашение нажать кнопку', h.includes('Обновить из Alfa'));

console.log('--- 6. в шапке написано, по какой сумме ищем ---');
h = render(day([kid('Ева', 15, 'came')], true));
t('суммы названы', h.includes('0 или 15'));

if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
console.log(NL + 'всё сошлось');
