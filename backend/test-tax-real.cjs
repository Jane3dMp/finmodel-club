// «Налоги РБ и ФОТ» по текущей загрузке: _taxReal. Запуск: node backend/test-tax-real.cjs
//
// 20.08.2026 Жанна: «эту таблицу хочу считать от ситуации на текущий момент — по загрузке,
// выручке и занятости педсостава». Таблица брала totals() (модель: цена × детей × групп × 4
// недели). Теперь берёт помесячный _realPnl() и сводит его к «в месяц».
//
// Ключевое, что проверяем: делим на число УЧЕБНЫХ месяцев (не на 12) и базу взносов считаем
// ПО МЕСЯЦУ — в декабре занятий меньше, ФОТ педагогов ниже, и обрезание НПД по нулю должно
// срабатывать в том месяце, где оно случилось, а не на средних.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
const near = (a, b) => Math.abs(a - b) < 0.51;

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const m = html.match(/\nfunction _taxReal\(\)\s*\{[\s\S]*?\n\}/m);
if (!m) { console.log('не найдено: _taxReal'); process.exit(1); }

// подставляем свой _realPnl — так проверяется именно сведение к «в месяц», без всей сетки
function build(realPnl, tax) {
  const S = { tax: tax };
  return new Function('S', '_realPnl', m[0] + '\nreturn _taxReal;')(S, realPnl);
}
const TAX = { usn: 6, fszn: 34, belgosstrah: 0.6, acquiring: 1.5 };
const mon = (ym, rev, wage) => ({
  ym: ym, mayOn: false, rev: rev, wage: wage, lessons: 10, seats: 40,
  usn: Math.round(rev * 0.06), acq: Math.round(rev * 0.015)
});

// два неравных месяца: сентябрь полный, декабрь короткий
const R = {
  adminFOT: 26300, npd: 0, warn: { noPrice: [], emptyGroups: [], silent: 0 },
  periods: [{ months: [mon('2026-09', 100000, 30000)] },
            { months: [mon('2026-12', 60000, 18000)] }]
};
const F = build(() => R, TAX)();

check('месяцев учтено ровно столько, сколько в расписании', F.mN === 2, 'mN=' + F.mN);
check('выручка — среднее за учебные месяцы, не за 12', near(F.rev, 80000), 'rev=' + F.rev);
check('ФОТ педагогов — среднее', near(F.wage, 24000), 'wage=' + F.wage);
check('УСН — среднее помесячных', near(F.usn, (6000 + 3600) / 2), 'usn=' + F.usn);
check('эквайринг — среднее помесячных', near(F.acq, (1500 + 900) / 2), 'acq=' + F.acq);
check('админ-ФОТ берётся как есть (не зависит от загрузки)', F.adminFOT === 26300);
check('база взносов = педагоги + админ', near(F.fotBase, 24000 + 26300), 'fotBase=' + F.fotBase);
check('ФСЗН 34% от базы', near(F.fszn, (24000 + 26300) * 0.34), 'fszn=' + F.fszn);
check('Белгосстрах 0,6% от базы', near(F.belg, (24000 + 26300) * 0.006), 'belg=' + F.belg);
check('итого = УСН + ФСЗН + Белгосстрах', near(F.taxTotal, F.usn + F.fszn + F.belg));
check('эквайринг в «итого налогов» НЕ входит', !near(F.taxTotal, F.usn + F.fszn + F.belg + F.acq));
check('помесячная разбивка отдана целиком', F.months.length === 2 && F.months[0].ym === '2026-09');
check('в разбивке есть ФСЗН и база по каждому месяцу',
  near(F.months[1].fotBase, 18000 + 26300) && near(F.months[1].fszn, (18000 + 26300) * 0.34));

// НПД больше всего ФОТ — база не должна уходить в минус, и обрезать надо помесячно
const R2 = {
  adminFOT: 5000, npd: 20000, warn: {},
  periods: [{ months: [mon('2026-09', 100000, 30000)] },   // база 30000+5000-20000 = 15000
            { months: [mon('2026-12', 60000, 6000)] }]     // база 6000+5000-20000 < 0 → 0
};
const F2 = build(() => R2, TAX)();
check('НПД вычитается из базы взносов', near(F2.months[0].fotBase, 15000), 'сент=' + F2.months[0].fotBase);
check('база не уходит в минус в слабом месяце', F2.months[1].fotBase === 0, 'дек=' + F2.months[1].fotBase);
check('среднее считается уже после обрезания по нулю', near(F2.fotBase, 7500), 'fotBase=' + F2.fotBase);

// пустое расписание — ничего не считаем и не делим на ноль
const F3 = build(() => ({ adminFOT: 0, npd: 0, warn: {}, periods: [{ months: [] }] }), TAX)();
check('пустое расписание: месяцев 0', F3.mN === 0);
check('пустое расписание: без деления на ноль', F3.rev === 0 && F3.taxTotal === 0);

// ставки читаются из S.tax, а не зашиты
const F4 = build(() => R, { usn: 6, fszn: 30, belgosstrah: 0, acquiring: 0 })();
check('ставка ФСЗН берётся из настроек', near(F4.fszn, (24000 + 26300) * 0.30), 'fszn=' + F4.fszn);
check('нулевой Белгосстрах не ломает расчёт', F4.belg === 0);

console.log(bad ? ('\nПРОВАЛЕНО проверок: ' + bad) : '\nВсе проверки прошли.');
process.exit(bad ? 1 : 0);
