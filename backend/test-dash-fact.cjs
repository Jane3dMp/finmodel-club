// Дашборд по факту: _dashFact. Запуск: node backend/test-dash-fact.cjs
//
// 20.08.2026 Жанна: «эту модель в таком виде предлагаю сделать именно по месяцу в реальном
// времени, исходя из набора текущего по сетке расписания». Дашборд считал по модели
// («Курсы и маржа»: цена × детей в группе × групп × 4 недели) — это план. Теперь та же
// вёрстка умеет показывать факт по живому расписанию, с выбором месяца.
//
// Главное, что проверяем: P&L СХОДИТСЯ (выручка минус все расходы = чистая прибыль) и даёт
// ту же цифру, что таблица расходов и помесячный расчёт отчёта. Три блока на разных вкладках
// обязаны отвечать одинаково — иначе Жанна получает три разных ответа на один вопрос.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
const near = (a, b) => Math.abs(a - b) < 1;

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const grab = (name) => {
  const m = html.match(new RegExp('\\nfunction ' + name + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
  if (!m) { console.log('не найдено: ' + name); process.exit(1); }
  return m[0];
};
const SRC = grab('_dashFact') + '\n' + grab('_expenseData');
const fmt1 = n => String(Math.round(n * 10) / 10);
const build = (S, realPnl) => new Function('S', '_realPnl', 'fmt1',
  SRC + '\nreturn {dash:_dashFact, exp:_expenseData};')(S, realPnl, fmt1);

const mon = (ym, rev, wage) => ({ ym, mayOn: ym < '2027-01', rev, wage, lessons: 100, seats: 40,
  usn: Math.round(rev * 0.06), acq: Math.round(rev * 0.015), pct: Math.round(rev * 0.05),
  revMay: ym < '2027-01' ? Math.round(rev * 0.3) : 0, seatsMay: 12 });

const S = {
  tax: { usn: 6, fszn: 34, belgosstrah: 0.6, acquiring: 1.5 },
  assume: { ownerSalary: 1000, weeksPerMonth: 4 },
  fixed: { 'Аренда Пожарный': 4000, 'Аренда Мира': 1000, 'Интернет': 100,
           'Ольга (менеджер)': 1500, 'Бухгалтер': 800 },
  fixedGroup: { 'Аренда Пожарный': 1, 'Аренда Мира': 1, 'Интернет': 1,
                'Ольга (менеджер)': 2, 'Бухгалтер': 2 },
  fixedPct: [{ name: 'Бытовые', pct: 5 }],
};
const ADMIN = 1500 + 800, OPS = 4000 + 1000 + 100, RENT = 4000 + 1000;
const R = { ops: OPS, adminFOT: ADMIN, npd: 0, warn: { noPrice: [], emptyGroups: ['Scratch 2'], silent: 7 },
  periods: [{ months: [mon('2026-09', 100000, 30000)] }, { months: [mon('2026-12', 60000, 18000)] }] };
const API = build(S, () => R);

// ---- средний месяц ----
const F = API.dash('');
check('учтены оба учебных месяца', F.mN === 2);
check('выручка — среднее по учебным месяцам', near(F.rev, 80000), 'rev=' + F.rev);
check('ЗП педагогов — среднее', near(F.wage, 24000));
check('постоянные берутся ЗА МЕСЯЦ, а не за год', near(F.fixed, OPS + ADMIN), 'fixed=' + F.fixed);
check('аренда — без ползунка сценария', near(F.rent, RENT), 'rent=' + F.rent);

const fbY = ((30000 + ADMIN) + (18000 + ADMIN)) / 2;
check('база взносов помесячная', near(F.fotBase, fbY), 'fotBase=' + F.fotBase);
check('ФСЗН и Белгосстрах по ставкам', near(F.fszn, fbY * 0.34) && near(F.belg, fbY * 0.006));
check('налоги = УСН + взносы', near(F.taxTotal, F.usn + F.fszn + F.belg));

// P&L должен сходиться сверху донизу
check('валовая = выручка − ЗП − материалы − эквайринг',
  near(F.grossProfit, F.rev - F.wage - F.mat - F.variableExtra));
check('операционная = валовая − постоянные − % от оборота',
  near(F.opProfit, F.grossProfit - F.fixed - F.pctExpense));
check('чистая = операционная − налоги − ЗП собственника',
  near(F.netProfit, F.opProfit - F.taxTotal - F.owner));
check('весь P&L сходится в одну строку',
  near(F.netProfit, F.rev - F.wage - F.variableExtra - F.fixed - F.pctExpense - F.taxTotal - F.owner),
  'netProfit=' + Math.round(F.netProfit));

// ---- та же цифра, что в таблице расходов ----
const E = API.exp('');
check('«чистая прибыль» дашборда = «остаётся» в таблице расходов',
  near(F.netProfit, E.profitY / E.mN), Math.round(F.netProfit) + ' vs ' + Math.round(E.profitY / E.mN));
check('выручка тоже совпадает', near(F.rev, E.revY / E.mN));

// ---- выбран один месяц ----
const D = API.dash('2026-12');
check('месяц один', D.mN === 1 && D.ym === '2026-12');
check('выручка месяца', near(D.rev, 60000));
check('постоянные за один месяц, не за девять', near(D.fixed, OPS + ADMIN));
check('ЗП собственника за один месяц', near(D.owner, 1000));
check('P&L месяца сходится',
  near(D.netProfit, D.rev - D.wage - D.variableExtra - D.fixed - D.pctExpense - D.taxTotal - D.owner));
const Edec = API.exp('2026-12');
check('месяц совпадает с таблицей расходов', near(D.netProfit, Edec.profitY),
  Math.round(D.netProfit) + ' vs ' + Math.round(Edec.profitY));
check('сумма двух месяцев = год по обеим функциям',
  near(API.dash('2026-09').rev + D.rev, E.revY));

// ---- майская цена и календарь доезжают до дашборда ----
check('видно деньги по майской цене', near(API.dash('2026-09').revMay, 30000));
// декабрь у клуба ещё майский: переход на полную цену стоит на 01.01.2027 (PLAN_DEF.full)
check('в декабре майская цена ещё действует', near(D.revMay, 18000), 'revMay=' + D.revMay);
const Rspr = { ops: OPS, adminFOT: ADMIN, npd: 0, warn: {},
  periods: [{ months: [mon('2027-03', 60000, 18000)] }] };
check('после перехода на полную цену майских денег нет',
  build(S, () => Rspr).dash('2027-03').revMay === 0);
check('занятия и места пробрасываются', D.lessons === 100 && D.kids === 40);
check('предупреждения пробрасываются', D.warn.silent === 7 && D.warn.emptyGroups.length === 1);

// ---- НПД срезает базу взносов помесячно, не на средних ----
const R2 = Object.assign({}, R, { npd: 25000 });
const F2 = build(S, () => R2).dash('');
const fb2 = (Math.max(0, 30000 + ADMIN - 25000) + Math.max(0, 18000 + ADMIN - 25000)) / 2;
check('НПД вычитается помесячно и не даёт минуса', near(F2.fotBase, fb2), 'fotBase=' + F2.fotBase);

// ---- пустое расписание: ничего не считаем, но и не падаем ----
const F3 = build(S, () => ({ ops: OPS, adminFOT: ADMIN, npd: 0, warn: {}, periods: [{ months: [] }] })).dash('');
check('пустое расписание: месяцев 0', F3.mN === 0);
check('пустое расписание: без NaN и деления на ноль',
  isFinite(F3.rev) && isFinite(F3.netProfit) && F3.rev === 0);
check('но постоянные расходы всё равно видны', near(F3.fixed, OPS + ADMIN),
  'аренда и команда платятся, даже если не запустилась ни одна группа');
check('пустое расписание даёт убыток размером с постоянные',
  near(F3.netProfit, -(OPS + ADMIN + 1000)), 'netProfit=' + Math.round(F3.netProfit));

console.log(bad ? ('\nПРОВАЛЕНО проверок: ' + bad) : '\nВсе проверки прошли.');
process.exit(bad ? 1 : 0);
