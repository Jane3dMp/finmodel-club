// Замок недельного прогноза — как он выглядит в таблице «Прогноз по всем».
// Запуск: node backend/test-weekplan-lock.cjs
//
// Серверную часть проверяет backend/test-weekplan-lock.php. Здесь — ячейка: под замком видна
// только цифра, без замка появляется поле для правки. Ошибиться тут легко и незаметно:
// например, положить в type="number" отформатированное «29 884» — браузер молча покажет пустое
// поле, и правка станет невозможной.
process.env.TZ = 'Europe/Minsk';   // в подсказке печатается время снимка
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }
// _gm разделяет тысячи НЕразрывным пробелом — для поиска по строке его надо привести
const NBSP = String.fromCharCode(160);
const plain = h => String(h).split(NBSP).join(String.fromCharCode(32));

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
let src = '';
for (const n of ['_progLocked', '_progLockBtn', '_progPlanCell', '_tsDMYHM']) {
  const m = html.match(new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  if (!m) { console.log('не найдено в index.html: ' + n); process.exit(1); }
  src += m[0] + '\n';
}
const TODAY = '2026-09-06';
const ctx = {
  _todayIso: () => TODAY,
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  _gm: n => Math.round(+n || 0).toLocaleString('ru-RU'),
};
const API = new Function('ctx', 'with (ctx) { ' + src + ' return {_progLocked,_progLockBtn,_progPlanCell}; }')(ctx);

const PAST = '2026-08-31';    // неделя 31.08–06.09 — та самая, что она зафиксировала случайно
const FUTURE = '2026-09-21';
const SNAP = { plan: 29884, lessons: 933, src: 'alfa', ts: '2026-08-30T22:00:03+03:00' };

/* ================= правило замка ================= */
check('прошедшая неделя без флага — заперта', API._progLocked(PAST, SNAP));
check('будущая неделя без флага — не заперта', !API._progLocked(FUTURE, SNAP));
check('без записи замка нет', !API._progLocked(PAST, null));
check('явный locked=false сильнее даты', !API._progLocked(PAST, { plan: 1, locked: false }));
check('явный locked=true сильнее даты', API._progLocked(FUTURE, { plan: 1, locked: true }));
// неделя, которая идёт прямо сейчас, тоже заперта: сравнивать факт уже начали
check('текущая неделя заперта', API._progLocked(TODAY, SNAP));

/* ================= ячейка под замком ================= */
const locked = API._progPlanCell(PAST, SNAP);
check('видна цифра', plain(locked).indexOf('29 884') > 0, locked);
check('поля для правки нет', locked.indexOf('<input') < 0, locked);
check('замок закрыт', locked.indexOf('🔒') > 0, locked);
check('и по нему снимают замок', locked.indexOf("progLock('2026-08-31',false)") > 0, locked);
// про исключение надо сказать прямо в ячейке: иначе перезапись под замком выглядит поломкой
check('в подсказке названо исключение — воскресный cron', locked.indexOf('cron') > 0, locked);
check('в подсказке — когда зафиксировано, местным временем',
      locked.indexOf('Зафиксирован 30.08 22:00') > 0, locked);

/* ================= ячейка без замка ================= */
const open = API._progPlanCell(PAST, Object.assign({}, SNAP, { locked: false }));
check('появилось поле', open.indexOf('<input') >= 0, open);
check('замок открыт', open.indexOf('🔓') > 0, open);
check('и по нему запирают', open.indexOf("progLock('2026-08-31',true)") > 0, open);
check('правка уходит на сервер', open.indexOf("progSetPlan('2026-08-31',this.value)") > 0, open);
// ⚠️ в type="number" может лежать только сырое число: «29 884» браузер покажет пустым полем
const val = (open.match(/value="([^"]*)"/) || [])[1];
eq('в поле сырое число, без разделителей', val, '29884');
check('поле числовое', open.indexOf('type="number"') > 0, open);

/* ================= пустая неделя ================= */
const none = API._progPlanCell(FUTURE, null);
eq('без записи — прочерк', none.indexOf('—') > 0, true);
check('и никаких кнопок', none.indexOf('progLock') < 0 && none.indexOf('<input') < 0, none);

/* ================= поправленная руками ================= */
const man = API._progPlanCell(PAST, { plan: 31000.5, src: 'manual', by: 'smalprince@gmail.com',
                                      ts: '2026-09-06T15:12:00+03:00', locked: true });
check('видно, что цифра ручная', man.indexOf('поправлено руками') > 0, man);
check('и кем', man.indexOf('smalprince@gmail.com') > 0, man);
check('дробная цифра округляется при показе', plain(man).indexOf('31 001') > 0, man);

/* ================= ноль — это значение, а не «пусто» ================= */
const zero = API._progPlanCell(PAST, { plan: 0, locked: true, ts: SNAP.ts });
check('ноль под замком показан цифрой', zero.indexOf('>0<') > 0, zero);

console.log(bad ? ('\n❌ провалов: ' + bad) : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
