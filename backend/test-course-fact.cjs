// Маржа курсов по расписанию: _courseFact. Запуск: node backend/test-course-fact.cjs
//
// 20.08.2026 Жанна: «клуба мат. стратегии уже нет» — а он висел в «Локомотивах» на дашборде.
// Блок «Топ и аутсайдеры» считался по справочнику «Курсы и маржа»: там у курса остались
// проставлены группы, хотя из расписания его давно убрали. В режиме «По расписанию» блок
// теперь строится из живой сетки — курс, которого нет в расписании, в него не попадает.
//
// Проверяется НАСТОЯЩИЙ код из index.html на разобранном вручную примере.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
const near = (a, b) => Math.abs(a - b) < 0.51;

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
function grab(name) {
  const m = html.match(new RegExp('\\nfunction ' + name + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  if (!m) { console.log('не найдена функция ' + name); process.exit(1); }
  return m[0];
}
const src = ['_planDays', '_planHolSet', '_courseMonth', '_realPnl', '_pnlMonths', '_courseFact']
  .map(grab).join('\n');

// Сентябрь 2026: вторников 5 (1, 8, 15, 22, 29). Три курса:
//   Лепка — идёт, двое подтвердивших;
//   Роблокс — идёт, один подтвердивший, дороже;
//   Химия — стоит в расписании, но НИКТО не подтвердил (группа не запущена).
// «Клуб математической стратегии» есть в справочнике курсов, но в расписании его нет —
// именно этот случай Жанна и увидела на экране.
function makeCtx() {
  return {
    S: {
      plan: { p1b: '2026-09-01', p1e: '2026-09-30', p2b: '2026-10-01', p2e: '2026-10-01',
              full: '2026-10-01', hol: '' },
      grid: [
        { day: 2, start: '10:00', course: 'Лепка', group: 'Лепка №1',
          kids: [{ n: 'Иванов Пётр', vbReply: 'yes' }, { n: 'Петров Иван', vbReply: 'yes' }] },
        { day: 2, start: '12:00', course: 'Роблокс', group: 'Роблокс №1',
          kids: [{ n: 'Сидоров Ким', vbReply: 'yes' }] },
        { day: 2, start: '14:00', course: 'Химия', group: 'Химия №1',
          kids: [{ n: 'Тихий Тимофей' }] },
      ],
      courses: [
        { name: 'Лепка', price: 20, groups: 1, fill: 2, groupSize: 8, visits: 1 },
        { name: 'Роблокс', price: 50, groups: 1, fill: 1, groupSize: 8, visits: 1 },
        { name: 'Химия', price: 30, groups: 1, fill: 4, groupSize: 8, visits: 1 },
        // курс живёт только в справочнике — в расписании его нет
        { name: 'Клуб математической стратегии', price: 40, groups: 2, fill: 6, groupSize: 8, visits: 1 },
      ],
      fixed: { 'Аренда': 1000, 'Администраторы': 500 },
      fixedGroup: { 'Аренда': 1, 'Администраторы': 2 },
      fixedPct: [],
      tax: { usn: 6, fszn: 34, belgosstrah: 0.6, acquiring: 0 },
      assume: { weeksPerMonth: 4, ownerSalary: 0 },
      _npdMonth: 0,
    },
  };
}
const ctx = makeCtx();
ctx._planCfg = () => ctx.S.plan;
ctx._planPrice = (course) => { const c = ctx.S.courses.find(o => o.name === course);
  return { p: +(c && c.price) || 0, m: +(c && c.price) || 0, known: !!c }; };
ctx.kidsOf = (l) => (Array.isArray(l.kids) ? l.kids : []);
ctx._nameKey = (s) => String(s || '').toLowerCase().trim();
ctx.wageForLesson = (course, students) => 5 * (students || 0);   // ставка: 5 р за ребёнка
const sandbox = new Proxy(ctx, { has: (t, k) => k in t, get: (t, k) => t[k], set: (t, k, v) => { t[k] = v; return true; } });
const run = (ym) => new Function('ctx', `with (ctx) { ${src}\n return _courseFact(${JSON.stringify(ym || '')}); }`)(sandbox);

const rows = run('2026-09');
const by = n => rows.find(r => r.name === n);

console.log('Курсы по расписанию за сентябрь:');
rows.forEach(r => console.log('  ' + r.name + ': выручка ' + Math.round(r.rev)
  + ', ЗП ' + Math.round(r.wage) + ', маржа ' + Math.round(r.margin)));

// ---- то, из-за чего правка ----
check('курса, которого нет в расписании, в списке нет',
  !by('Клуб математической стратегии'),
  'он есть в справочнике курсов с двумя группами, но в сетке его нет');
check('группа без подтвердивших не считается запущенной', !by('Химия'),
  'в расписании стоит, но никто не подтвердил — ни выручки, ни зарплаты');
check('в списке ровно те курсы, что реально идут', rows.length === 2,
  'курсов: ' + rows.map(r => r.name).join(', '));

// ---- арифметика: Лепка = 2 ребёнка × 20 р × 5 занятий = 200, ЗП 5×2×5 = 50 ----
check('выручка курса по подтвердившим', near(by('Лепка').rev, 200), 'rev=' + by('Лепка').rev);
check('ЗП курса по ставке за занятие', near(by('Лепка').wage, 50), 'wage=' + by('Лепка').wage);
check('маржа = выручка − ЗП', near(by('Лепка').margin, 150));
check('процент маржи', near(by('Лепка').marginPct, 75), 'marginPct=' + by('Лепка').marginPct);
check('занятий у курса', near(by('Лепка').lessons, 5));
check('детей у курса — без повторов', by('Лепка').kids === 2);
check('групп у курса', by('Лепка').groups === 1);

// Роблокс: 1 ребёнок × 50 × 5 = 250, ЗП 5×1×5 = 25 → маржа 225
check('второй курс посчитан отдельно', near(by('Роблокс').margin, 225), 'margin=' + by('Роблокс').margin);
check('дорогой курс с одним ребёнком выгоднее дешёвого с двумя',
  by('Роблокс').margin > by('Лепка').margin,
  'именно так дашборд и должен ранжировать «Локомотивы»');

// ---- сумма по курсам должна сходиться с общей выручкой месяца ----
const R = new Function('ctx', `with (ctx) { ${src}\n return _realPnl(); }`)(sandbox);
const sep = R.periods[0].months[0];
check('сумма выручки курсов = выручка месяца',
  near(rows.reduce((a, r) => a + r.rev, 0), sep.rev), 'курсы=' + rows.reduce((a, r) => a + r.rev, 0) + ', месяц=' + sep.rev);
check('сумма ЗП курсов = ЗП месяца',
  near(rows.reduce((a, r) => a + r.wage, 0), sep.wage));

// ---- без месяца: среднее по учебным месяцам, состав курсов тот же ----
const avg = run('');
check('в среднем за год те же курсы', avg.length === 2);
check('месяца нет в расписании — пусто, без падения', run('2030-01').length === 0);

console.log(bad ? ('\nПРОВАЛЕНО проверок: ' + bad) : '\nВсе проверки прошли.');
process.exit(bad ? 1 : 0);
