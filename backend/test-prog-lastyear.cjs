// «Прогноз по всем»: сравнение с тем же периодом прошлого учебного года.
// Запуск: node backend/test-prog-lastyear.cjs
//
// Ловушек здесь три, и каждая тихо врёт, а не падает:
//   1) сдвиг на календарный год ставит понедельник против воскресенья, а у клуба выходной даёт
//      втрое больше будня — «падение на 40%» было бы просто сдвигом календаря;
//   2) неполная прошлогодняя неделя (подтянули не все дни) выглядит как обвал;
//   3) неделя, которая ещё идёт, сравнивается тремя днями против семи.
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
for (const n of ['_progLyMon', '_progLyFact', '_progLyMissing', '_progWeeks', '_progWeekAlfa',
                 '_progLocked', '_progLockBtn', '_progPlanCell', '_tsDMYHM', '_kassaDayWord', '_progHtml']) {
  const m = html.match(new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  if (!m) { console.log('не найдено в index.html: ' + n); process.exit(1); }
  src += m[0] + '\n';
}

const TODAY = '2026-09-10';                 // четверг: неделя 31.08 закрыта, 07.09 ещё идёт
const dIso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const store = {};
const put = (iso, present, all) => { store[iso] = { present: present, all: all, lessons: 12, planned: 0 }; };
// этот год: полная неделя 31.08–06.09 и три дня начавшейся 07.09
['2026-08-31','2026-09-01','2026-09-02','2026-09-03','2026-09-04','2026-09-05','2026-09-06']
  .forEach(k => put(k, 1000, 1200));        // 1100 в день → 7 700 за неделю
['2026-09-07','2026-09-08','2026-09-09'].forEach(k => put(k, 1000, 1200));   // 3 300
// прошлый год: та же неделя целиком и следующая — только три дня
['2025-09-01','2025-09-02','2025-09-03','2025-09-04','2025-09-05','2025-09-06','2025-09-07']
  .forEach(k => put(k, 500, 600));          // 550 в день → 3 850 за неделю
['2025-09-08','2025-09-09','2025-09-10'].forEach(k => put(k, 500, 600));

const ctx = {
  _realStore: store,
  _weekPlans: { '2026-08-31': { plan: 22288, src: 'alfa', ts: '2026-08-30T22:00:03+03:00' } },
  _rukProg: () => ({ plans: {} }),
  _rukPokaz: () => ({ curYear: '2026/27' }),
  _todayIso: () => TODAY,
  _dIso: dIso,
  _RU_MON: ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'],
  _gm: n => Math.round(+n || 0).toLocaleString('ru-RU'),
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_progLyMon,_progLyFact,_progLyMissing,_progWeeks,_progHtml}; }')(ctx);

const W = k => ({ key: k, mon: new Date(k + 'T12:00:00'),
                  sun: (() => { const d = new Date(k + 'T12:00:00'); d.setDate(d.getDate() + 6); return d; })() });

/* ================= сдвиг ровно на 52 недели ================= */
const ly = API._progLyMon(W('2026-08-31'));
eq('прошлогодний понедельник', dIso(ly), '2025-09-01');
eq('это тоже понедельник', ly.getDay(), new Date('2026-08-31T12:00:00').getDay());
check('и это НЕ календарный минус год', dIso(ly) !== '2025-08-31', dIso(ly));
// через переход года сдвиг не должен ломаться
eq('январская неделя тоже сдвигается на 364 дня', dIso(API._progLyMon(W('2027-01-04'))), '2026-01-05');

/* ================= факт прошлогодней недели ================= */
const f1 = API._progLyFact(W('2026-08-31'));
eq('все семь дней на месте', f1.days, 7);
eq('факт — среднее без пропусков и с ними', f1.fact, 7 * 550);
eq('период назван', f1.from + '…' + f1.to, '2025-09-01…2025-09-07');

const f2 = API._progLyFact(W('2026-09-07'));
eq('неполная неделя: дней меньше семи', f2.days, 3);
eq('и сумма только по ним', f2.fact, 3 * 550);

eq('неделя без прошлогодних данных', API._progLyFact(W('2026-10-05')).days, 0);

/* ================= чего не хватает ================= */
const need = API._progLyMissing();
eq('не хватает четырёх дней', need.length, 4);
check('и это дни прошлого года', need.every(k => k >= '2025-09-11' && k <= '2025-09-14'), need.join(', '));
check('будущие недели не тянем — сравнивать не с чем',
      !need.some(k => k > '2025-09-14'), need.join(', '));

/* ================= таблица ================= */
const h = API._progHtml();
const body = h.slice(h.indexOf('<tbody>'), h.indexOf('</tbody>'));
const trs = body.split('<tr').slice(1);
const rawCells = tr => (tr.match(/<td[^>]*>[\s\S]*?<\/td>/g) || []);
const cellsOf = tr => (tr.match(/<td[^>]*>([\s\S]*?)<\/td>/g) || [])
  .map(td => td.replace(/<[^>]+>/g, '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim());

check('колонка «Год назад» появилась', h.indexOf('>Год назад<') > 0);
check('колонка «Рост» появилась', h.indexOf('>Рост<') > 0);

const r1 = cellsOf(trs[0]);                       // неделя 31.08–06.09
eq('строка недели', r1[1], '31.08–06.09');
eq('факт этого года', r1[3], '7 700');
eq('учебных дней: столько же, сколько год назад', r1[5], '7 / 7');
eq('прошлый год', r1[6], '3 850');
eq('рост', r1[7], '+100%');

const r2 = cellsOf(trs[1]);                       // неделя 07.09–13.09, ещё идёт
eq('идущая неделя: прошлый год помечен неполным', r2[6], '1 650 ⚠️');   // 3 дня × 550
// цифра роста тут есть, но она обманчива: три дня против семи. Красить её зелёным нельзя —
// именно цвет читается первым, а он сказал бы «рост вдвое», которого никто не измерял
const grCell = rawCells(trs[1])[7];
check('рост идущей недели приглушён, а не зелёный',
      grCell.indexOf('color:var(--muted)') > 0 && grCell.indexOf('var(--green)') < 0, grCell);
// а у закрытой недели — наоборот, полноценный зелёный
check('у закрытой недели рост покрашен', rawCells(trs[0])[7].indexOf('var(--green)') > 0,
      rawCells(trs[0])[7]);
check('в подсказке сказано, что неделя идёт', trs[1].indexOf('неделя ещё идёт') > 0);
check('и что прошлый год неполон', trs[1].indexOf('из 7 дней') > 0);

const r3 = cellsOf(trs[2]);                       // 14.09–20.09 — будущая
eq('будущая неделя: прошлого года нет', r3[6], '—');
eq('и роста нет', r3[7], '—');

/* ================= итог ================= */
const tot = cellsOf(trs[trs.length - 1]);
eq('итог прошлого года — только по сопоставимым неделям', tot[5], '3 850');
eq('и рост по ним же', tot[6], '+100%');
check('неполная и идущая недели в итог не попали', trs[trs.length - 1].indexOf('Сопоставимых недель: 1') > 0,
      trs[trs.length - 1]);

/* ================= кнопка ================= */
check('кнопка называет объём работы', h.indexOf('Подтянуть прошлый год (4 дня)') > 0,
      h.slice(Math.max(0, h.indexOf('Подтянуть прошлый год') - 40), h.indexOf('Подтянуть прошлый год') + 60));

/* ================= раздел стоит колонкой по центру =================
   Незакрытый <div> здесь не «съедет вёрстка», а утащит за собой весь дашборд. */
check('раздел обёрнут в центрирующую колонку', h.indexOf('<div class="progwrap">') === 0, h.slice(0, 60));
eq('баланс <div>', (h.match(/<div/g) || []).length, (h.match(/<\/div>/g) || []).length);
eq('баланс <table>', (h.match(/<table/g) || []).length, (h.match(/<\/table>/g) || []).length);
check('у таблицы нет своей ширины — она занимает всю колонку', h.indexOf('tscroll" style="max-width:760px') < 0);

/* ================= НЕРАВНЫЕ НЕДЕЛИ =================
   Живой случай 07.09.2026: учебный год начался во вторник, и в неделе 31.08–06.09 занятия были
   5 дней против 6 год назад. Сравнение итога с итогом в такой паре занижает рост — Жанна это
   заметила глазами. Сравнивать надо на учебный день. */
{
  // 31.08 и 01.09 этого года делаем днями без занятий: остаётся 5 учебных дней из 7
  ['2026-08-31', '2026-09-01'].forEach(k => { store[k] = { present: 0, all: 0, lessons: 0, planned: 0 }; });
  const h2 = API._progHtml();
  const body2 = h2.slice(h2.indexOf('<tbody>'), h2.indexOf('</tbody>'));
  const trs2 = body2.split('<tr').slice(1);
  const c = cellsOf(trs2[0]);

  eq('учебных дней: 5 против 7', c[5], '5 / 7');
  eq('факт упал до пяти дней', c[3], '5 500');
  // итог к итогу дал бы +43%, а на учебный день — ровно +100%: 1 100 против 550
  eq('рост считается на учебный день', c[7], '+100%/дн');
  check('в подсказке названы обе длины недели',
        trs2[0].indexOf('5 дней с занятиями против 7') > 0, trs2[0]);
  const NBSP = String.fromCharCode(160);
  const plain = x => String(x).split(NBSP).join(String.fromCharCode(32));
  check('и обе цифры на день',
        plain(trs2[0]).indexOf('1 100 против 550') > 0, trs2[0]);

  // ⚠️ неравную неделю нельзя пускать в годовой итог: там снова сложится итог с итогом
  const tot2 = cellsOf(trs2[trs2.length - 1]);
  eq('в годовой итог неравная неделя не пошла', tot2[5], '—');
  eq('и роста за год нет', tot2[6], '—');

  // равные недели считаются по-прежнему — итогом, без пометки
  ['2026-08-31', '2026-09-01'].forEach(k => { store[k] = { present: 1000, all: 1200, lessons: 12, planned: 0 }; });
  const c3 = cellsOf(API._progHtml().slice(0).split('<tr').slice(1)[1]);
  check('у равных недель пометки «/дн» нет', (c3.join(' ')).indexOf('/дн') < 0, c3.join(' | '));
}


console.log(bad ? ('\n❌ провалов: ' + bad) : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
