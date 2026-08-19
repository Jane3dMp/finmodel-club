// Реальная картина при текущей загрузке: выручка, расходы, остаток.
// Запуск: node backend/test-real-pnl.cjs
//
// 19.08.2026 Жанна: «тут нужны расходы согласно загрузки и запущенных групп —
// сделай мне реальную картину при загрузке на данный момент». «Дашборд» считает
// по модели (цена × детей в группе × групп × 4 недели), а в отчёте по набору
// нужен факт: живое расписание, живые дети, живые даты.
//
// Здесь проверяется НАСТОЯЩАЯ функция _realPnl из index.html на понятном
// вручную примере: одна группа, один месяц — числа должны сойтись на бумаге.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
function grab(name) {
  const re = new RegExp('\\nfunction ' + name + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm');
  const m = html.match(re);
  if (!m) { console.log('не найдена функция ' + name); process.exit(1); }
  return m[0];
}
const src = ['_planDays', '_planHolSet', '_realPnl'].map(grab).join('\n');

// Расписание: две группы по вторникам. Первая запущена (2 подтвердивших),
// вторая — заготовка без единого подтверждения, её считать нельзя.
function makeCtx() {
  return {
    S: {
      plan: { p1b: '2026-09-01', p1e: '2026-09-30', p2b: '2026-10-01', p2e: '2026-10-31',
              full: '2026-10-01', hol: '' },   // полная цена с октября: сентябрь идёт по майской
      grid: [
        { day: 2, course: 'Лепка', group: 'Лепка №1', teacher: 'Иванова',
          kids: [ { n: 'Иванов Пётр', vbReply: 'yes' },
                  { n: 'Петров Иван', may: true },
                  { n: 'Сидоров Ким', vbReply: 'no' },      // отказ — не платит и на ставку не влияет
                  { n: 'Тихий Тимофей' } ] },               // молчит — в факт не берём
        { day: 2, course: 'Лепка', group: 'Лепка №2', teacher: 'Иванова', kids: [ { n: 'Пустой Павел' } ] },
      ],
      courses: [ { name: 'Лепка', price: 20, mayPrice: 10, wage: 0 } ],
      fixed: { 'Аренда': 1000, 'Администраторы': 500 },
      fixedGroup: { 'Аренда': 1, 'Администраторы': 2 },
      fixedPct: [],
      tax: { usn: 6, fszn: 34, belgosstrah: 0.6, acquiring: 0 },
      assume: { weeksPerMonth: 4, ownerSalary: 0 },
      _npdMonth: 0,
    },
    // сентябрь 2026: вторников — 1, 8, 15, 22, 29 = 5
    _planCfg: null, _planPrice: null, kidsOf: null, _nameKey: null, wageForLesson: null,
  };
}
const ctx = makeCtx();
ctx._planCfg = () => ctx.S.plan;
ctx._planPrice = (course) => { const c = ctx.S.courses.find(o => o.name === course);
  return { p: +(c && c.price) || 0, m: +(c && c.mayPrice) || 0, known: !!c }; };
ctx.kidsOf = (l) => (Array.isArray(l.kids) ? l.kids : []);
ctx._nameKey = (s) => String(s || '').toLowerCase().trim();
ctx.wageForLesson = (course, students) => 5 * (students || 0);   // ставка: 5 р за ребёнка

const sandbox = new Proxy(ctx, { has: (t, k) => k in t, get: (t, k) => t[k], set: (t, k, v) => { t[k] = v; return true; } });
const R = new Function('ctx', `with (ctx) { ${src}\n return _realPnl(); }`)(sandbox);

const sep = R.periods[0].months[0];
console.log('Сентябрь 2026 (5 вторников, одна запущенная группа из двух):');
console.log('  занятий ' + sep.lessons + ', выручка ' + sep.rev + ', ЗП ' + sep.wage
  + ', налоги ' + sep.taxes + ', остаток ' + sep.profit);

check('считаем только запущенную группу', sep.lessons === 5, 'занятий: ' + sep.lessons);
// цена майская (10) у оплатившего + полная (20) у ответившего «да» = 30 за занятие × 5
check('выручка по подтвердившим, майская цена учтена', sep.rev === 150, 'выручка: ' + sep.rev);
// ставка 5 × 2 подтвердивших = 10 за занятие × 5 занятий
check('ЗП педагога — по числу подтвердивших', sep.wage === 50, 'ЗП: ' + sep.wage);
check('постоянные расходы разделены на операционные и админ-ФОТ',
  sep.ops === 1000 && sep.adminFOT === 500, 'ops=' + sep.ops + ', admin=' + sep.adminFOT);
// УСН 6% от 150 = 9; взносы 34,6% от (50+500) = 190,3 → 199,3
check('налоги: УСН от выручки + взносы от фонда зарплаты',
  Math.round(sep.taxes) === 199, 'налоги: ' + sep.taxes);
// 150 − 50 − 1000 − 500 − 199 = −1599
check('остаток считается со всеми расходами', sep.profit === Math.round(150 - 50 - 1000 - 500 - 199.3),
  'остаток: ' + sep.profit);

console.log('\nЧто журнал показывает предупреждением:');
check('пустая группа названа', R.warn.emptyGroups.indexOf('Лепка №2') >= 0,
  JSON.stringify(R.warn.emptyGroups));
check('молчащие дети посчитаны', R.warn.silent > 0, 'молчат: ' + R.warn.silent);
check('курс с ценой не попал в жалобы', R.warn.noPrice.length === 0, JSON.stringify(R.warn.noPrice));

console.log('\nИтоги:');
check('год = сумма периодов', R.year.rev === R.periods[0].rev + R.periods[1].rev);
check('месяцы посчитаны', R.year.months === 2, 'месяцев: ' + R.year.months);
check('отказавшийся ребёнок не платит и не поднимает ставку', sep.rev === 150 && sep.wage === 50);

// Блок должен ещё и нарисоваться: вёрстка собирается той же функцией, что
// на экране, поэтому опечатка в шаблоне видна здесь, а не у Жанны.
const srcHtml = ['_planLabel', '_realPnlHTML'].map(grab).join('\n');
ctx.esc = (x) => String(x == null ? '' : x);
ctx.fmt = (n) => String(Math.round(n));
ctx.fmt1 = (n) => String(Math.round(n * 10) / 10);
ctx.PLAN_MON = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const html2 = new Function('ctx', 'with (ctx) { ' + src + '\n' + srcHtml + '\n return _realPnlHTML(); }')(sandbox);

console.log('\nБлок на экране:');
check('блок собрался', typeof html2 === 'string' && html2.length > 500, 'длина: ' + (html2 || '').length);
check('есть заголовок', /Расходы и прибыль при этой загрузке/.test(html2));
check('есть строки месяцев', /сентября 2026/.test(html2), 'нет месяца в таблице');
check('видно, что группа не запущена', /Не запущены/.test(html2));
check('сказано про учебные месяцы и лето', /лето в расчёт не входит/.test(html2));
check('шаблонные вставки раскрыты', html2.indexOf('${') < 0);

console.log(bad ? '\nПЛОХО: ' + bad + '\n' : '\nВсё хорошо.\n');
process.exit(bad ? 1 : 0);
