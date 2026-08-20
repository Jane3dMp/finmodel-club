// Постатейные расходы при текущей загрузке: _expenseData. Запуск: node backend/test-expenses.cjs
//
// 20.08.2026 Жанна: «давай более расширенную таблицу по расходам». В сводке всё было свалено в
// «постоянные» и «налоги» — куда именно уходят деньги, видно не было.
//
// Главное, что проверяем: разбивка СХОДИТСЯ — сумма всех статей плюс остаток равна выручке.
// Если статью посчитать дважды или потерять, «остаётся» станет враньём, а именно эту цифру
// Жанна и смотрит.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
const near = (a, b) => Math.abs(a - b) < 1;

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const m = html.match(/\nfunction _expenseData\([^)]*\)\s*\{[\s\S]*?\n\}/m);
if (!m) { console.log('не найдено: _expenseData'); process.exit(1); }
// _pnlMonths схлопывает месяцы по ym: осень и весна могут задеть один календарный месяц
const mp = html.match(/\nfunction _pnlMonths\([^)]*\)\s*\{[\s\S]*?\n\}/m);
if (!mp) { console.log('не найдено: _pnlMonths'); process.exit(1); }
const fmt1 = n => String(Math.round(n * 10) / 10);
const build = (S, realPnl) =>
  new Function('S', '_realPnl', 'fmt1', mp[0] + '\n' + m[0] + '\nreturn _expenseData;')(S, realPnl, fmt1);

const mon = (ym, rev, wage) => ({ ym, mayOn: false, rev, wage, lessons: 10, seats: 40,
  usn: Math.round(rev * 0.06), acq: Math.round(rev * 0.015) });

const S = {
  tax: { usn: 6, fszn: 34, belgosstrah: 0.6, acquiring: 1.5 },
  assume: { ownerSalary: 1000 },
  fixed: { 'Аренда Пожарный': 4000, 'Интернет': 100, 'Ольга (менеджер)': 1500, 'Бухгалтер': 800 },
  fixedGroup: { 'Аренда Пожарный': 1, 'Интернет': 1, 'Ольга (менеджер)': 2, 'Бухгалтер': 2 },
  fixedPct: [{ name: 'Бытовые', pct: 5 }, { name: '', pct: 0 }],
};
const ADMIN = 1500 + 800, OPS = 4000 + 100;
const R = { adminFOT: ADMIN, npd: 0, warn: { noPrice: [], emptyGroups: ['Scratch 2'], silent: 7 },
  periods: [{ months: [mon('2026-09', 100000, 30000)] }, { months: [mon('2026-12', 60000, 18000)] }] };
const E = build(S, () => R)();

const revY = 160000, wageY = 48000, mN = 2;
const usnY = 6000 + 3600, acqY = 1500 + 900, pctY = revY * 0.05;
const fbY = (30000 + ADMIN) + (18000 + ADMIN);
const fsznY = fbY * 0.34, belgY = fbY * 0.006, ownerY = 1000 * mN;
const expect = wageY + ADMIN * mN + OPS * mN + pctY + acqY + usnY + fsznY + belgY + ownerY;

check('учебных месяцев столько же, сколько в расписании', E.mN === 2);
check('выручка за год взята из факта', near(E.revY, revY), 'revY=' + E.revY);
check('итог расходов = сумма всех статей', near(E.totalY, expect), E.totalY + ' vs ' + expect);
check('расходы + остаток = выручка (разбивка сходится)', near(E.totalY + E.profitY, revY),
  'total=' + Math.round(E.totalY) + ' profit=' + Math.round(E.profitY));
check('сумма групп равна итогу', near(E.G.reduce((a, g) => a + g.y, 0), E.totalY));

const grp = t => E.G.find(g => g.t === t);
check('ЗП педагогов — из факта', near(grp('Зарплата педагогов').y, wageY));
check('админ-команда: статьи группы 2, × число месяцев', near(grp('Админ-команда').y, ADMIN * mN));
check('постоянные: остальные статьи, × число месяцев', near(grp('Постоянные расходы').y, OPS * mN));
check('от оборота = проценты + эквайринг', near(grp('От оборота').y, pctY + acqY));
// взносы разделены на две части: с ФОТ педагогов они растут вместе с загрузкой, а с админ-ФОТ
// платятся, даже если не запустится ни одна группа. Пока они лежали в одной «зависящей» группе,
// выноска «идёт в любом случае» занижала сумму, а порог безубыточности выходил ниже реального.
const conAdmin = ADMIN * mN * 0.346, conTeach = wageY * 0.346;
check('налоги с оборота = УСН + взносы с ФОТ педагогов',
  near(grp('Налоги с оборота и ФОТ педагогов').y, usnY + conTeach),
  'группа=' + grp('Налоги с оборота и ФОТ педагогов').y);
check('взносы с админ-команды вынесены в постоянные',
  near(grp('Взносы с админ-команды').y, conAdmin), 'группа=' + grp('Взносы с админ-команды').y);
check('вместе они дают прежнюю сумму УСН и взносов',
  near(grp('Налоги с оборота и ФОТ педагогов').y + grp('Взносы с админ-команды').y,
       usnY + fsznY + belgY));
check('ЗП собственника × число месяцев', near(grp('Зарплата собственника').y, ownerY));

check('каждая статья постоянных — отдельной строкой', grp('Постоянные расходы').rows.length === 2);
check('дорогая статья сверху', grp('Постоянные расходы').rows[0].n === 'Аренда Пожарный');
check('нулевая %-статья не показывается', grp('От оборота').rows.length === 2);
check('«в месяц» у постоянной статьи = сама ставка, не делённая заново',
  near(grp('Постоянные расходы').rows[0].mo, 4000), 'mo=' + grp('Постоянные расходы').rows[0].mo);

check('зависит от загрузки: ЗП + оборотные + УСН + взносы с педагогов',
  near(E.zavY, wageY + pctY + acqY + usnY + conTeach), 'zavY=' + E.zavY);
check('идёт в любом случае: постоянные + админ + собственник + взносы с админ-ФОТ',
  near(E.fixY, ADMIN * mN + OPS * mN + ownerY + conAdmin), 'fixY=' + E.fixY);
check('зависимое + постоянное = весь расход', near(E.zavY + E.fixY, E.totalY));
check('предупреждения проброшены как есть', E.warn.silent === 7 && E.warn.emptyGroups.length === 1);

// НПД: вычет из базы взносов обрезается по нулю ПОМЕСЯЧНО, а не на годовой сумме
const R2 = Object.assign({}, R, { npd: 25000 });
const E2 = build(S, () => R2)();
const fb2 = Math.max(0, 30000 + ADMIN - 25000) + Math.max(0, 18000 + ADMIN - 25000);
// НПД (25 000) больше админ-ФОТ, поэтому постоянной части взносов не остаётся вовсе
check('НПД срезает базу взносов помесячно',
  near(grp2(E2, 'Налоги с оборота и ФОТ педагогов').y, usnY + fb2 * 0.346),
  'налоги=' + grp2(E2, 'Налоги с оборота и ФОТ педагогов').y);
check('при НПД больше админ-ФОТ постоянных взносов нет', !grp2(E2, 'Взносы с админ-команды'));
function grp2(E, t) { return E.G.find(g => g.t === t); }

// нет ни одной запущенной группы — таблицу рисовать не из чего, но и падать нельзя
const E3 = build(S, () => ({ adminFOT: ADMIN, npd: 0, warn: {}, periods: [{ months: [] }] }))();
check('пустое расписание: месяцев 0', E3.mN === 0);
check('пустое расписание: без деления на ноль', isFinite(E3.totalY) && isFinite(E3.profitY));

// собственник без зарплаты — лишней группы быть не должно
const S4 = Object.assign({}, S, { assume: { ownerSalary: 0 } });
const E4 = build(S4, () => R)();
check('нулевая ЗП собственника не создаёт пустую группу', !E4.G.some(g => g.t === 'Зарплата собственника'));

// «Остаётся» здесь и «Остаётся» в помесячной таблице «Отчёта по набору» — одна и та же цифра.
// Обе считаются от _realPnl, но разными путями; если формулы разойдутся, Жанна увидит два
// разных ответа на один вопрос. Повторяем формулу профита из _realPnl и сверяем.
const profitRealPnl = R.periods.reduce((a, P) => a + P.months.reduce((b, m) => {
  const fb = Math.max(0, m.wage + R.adminFOT - R.npd);
  const taxes = m.usn + fb * 0.346;
  return b + (m.rev - m.wage - OPS - ADMIN - m.rev * 0.05 - m.acq - taxes - 1000);
}, 0), 0);
check('«остаётся» совпадает с помесячным расчётом в отчёте', near(E.profitY, profitRealPnl),
  Math.round(E.profitY) + ' vs ' + Math.round(profitRealPnl));

// ---- помесячный режим: Жанна переключает месяц, и всё должно пересчитаться на ЭТОТ месяц ----
// «по кол-ву дней месяца с учетом выходных» — число занятий приходит из _planDays (праздники и
// выходные уже вычтены), а постоянные расходы за один месяц берутся один раз, не за девять.
const Edec = build(S, () => R)('2026-12');
check('выбран один месяц — он один и считается', Edec.mN === 1 && Edec.months[0].ym === '2026-12');
check('выручка — только этого месяца', near(Edec.revY, 60000), 'revY=' + Edec.revY);
check('ЗП педагогов — только этого месяца', near(grp2(Edec, 'Зарплата педагогов').y, 18000));
check('постоянные берутся ЗА ОДИН месяц, а не за девять',
  near(grp2(Edec, 'Постоянные расходы').y, OPS), 'ops=' + grp2(Edec, 'Постоянные расходы').y);
check('админ-команда — тоже за один месяц', near(grp2(Edec, 'Админ-команда').y, ADMIN));
check('ЗП собственника — за один месяц', near(grp2(Edec, 'Зарплата собственника').y, 1000));
check('налоги считаются от этого месяца',
  near(grp2(Edec, 'Налоги с оборота и ФОТ педагогов').y + grp2(Edec, 'Взносы с админ-команды').y,
       3600 + (18000 + ADMIN) * 0.346));
check('разбивка по месяцу тоже сходится', near(Edec.totalY + Edec.profitY, 60000));
check('«в месяц» в режиме одного месяца = сама сумма', near(Edec.mo(Edec.totalY), Edec.totalY));
check('весь список месяцев остаётся для переключателя', Edec.all.length === 2);
check('выбранный месяц запомнен в данных', Edec.ym === '2026-12');

const Esep = build(S, () => R)('2026-09');
check('другой месяц — другие цифры', near(Esep.revY, 100000) && !near(Esep.totalY, Edec.totalY));
check('сумма двух месяцев = год', near(Esep.revY + Edec.revY, E.revY));
check('расходы двух месяцев = годовые', near(Esep.totalY + Edec.totalY, E.totalY),
  Math.round(Esep.totalY + Edec.totalY) + ' vs ' + Math.round(E.totalY));

const Enone = build(S, () => R)('2027-03');
check('месяца нет в расписании — пустой расчёт, без падения', Enone.mN === 0 && isFinite(Enone.totalY));

console.log(bad ? ('\nПРОВАЛЕНО проверок: ' + bad) : '\nВсе проверки прошли.');
process.exit(bad ? 1 : 0);
