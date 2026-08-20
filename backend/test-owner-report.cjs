// Отчёт владельца: _ownerReport. Запуск: node backend/test-owner-report.cjs
//
// 20.08.2026. Жанна попросила считать майских подтвердившими («они же вернулись и подтвердили»).
// Правку сделали в воронке спидометров, а _ownerReport остался на старом правиле — и это
// оказалось дороже всего: он кормит таблицу «Движение по группам», «⚠ Группы риска» и карточки
// «Оборот / нед (подтв.)». Ребёнок, оплативший 💰 и не ответивший в Viber (а майский шаблон
// «планируете ли» и не спрашивает), падал в «Молчат», не давал оборота и тянул группу в риск:
// полностью оплаченная группа показывала заполненность 0%.
//
// Здесь проверяется НАСТОЯЩИЙ код из index.html: функция вырезается из файла и исполняется.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const m = html.match(/\nfunction _ownerReport\([^)]*\)\s*\{[\s\S]*?\n\}/m);
if (!m) { console.log('не найдено: _ownerReport'); process.exit(1); }

function run(grid, courses) {
  const ctx = {
    S: { grid, courses: courses || [{ name: 'Лепка', groupSize: 4, price: 100, visits: 1 }],
         assume: { weeksPerMonth: 4 } },
    kidsOf: l => (Array.isArray(l.kids) ? l.kids : []),
    _nameKey: n => String(n || '').trim().toLowerCase().replace(/\s+/g, ' '),
    // настоящий courseFromLib ещё умеет искать по семейству курса — здесь это неважно,
    // проверяем счётчики, а не поиск курса
    courseFromLib: null,
  };
  ctx.courseFromLib = name => ctx.S.courses.find(c => c.name === name) || null;
  return new Function('ctx', 'with (ctx) { ' + m[0] + '\n return _ownerReport(); }')(ctx);
}
const les = (group, kids) => ({ id: 'l1', day: 2, start: '10:00', course: 'Лепка', group,
  teacher: 'Иванова', kids });

// ---- ровно тот случай, из-за которого всё затевалось ----
// группа на 4 места: двое оплатили майский и молчат, один ответил «да», один молчит
const R = run([les('Лепка №1', [
  { n: 'Майский Матвей', may: true },
  { n: 'Майская Мила', may: true },
  { n: 'Ответил Артём', vbReply: 'yes' },
  { n: 'Молчит Мария' },
])]);
const g = R.rows[0];

check('оплатившие майский попали в 🟢', g.yes === 3, 'yes=' + g.yes + ' (ожидались двое майских и Артём)');
check('в «Молчат» остался только настоящий молчун', g.none === 1, 'none=' + g.none);
check('заполненность считается по подтвердившим', g.fill === 75, 'fill=' + g.fill + '%');
check('оплаченная группа больше не «группа риска»', g.risk === false);
check('оборот недели считает и майских', g.revWeek === 300, 'revWeek=' + g.revWeek);
check('итог по обороту сходится с группой', R.T.revWeekYes === 300);

// ---- отказ перевешивает оплату ----
const R2 = run([les('Лепка №1', [
  { n: 'Передумал Пётр', may: true, vbReply: 'no' },   // заплатил, потом отказался
  { n: 'Крестик Кирилл', may: true, no: true },        // заплатил, стоит ❌
  { n: 'Честный Харитон', may: true },
])]);
const g2 = R2.rows[0];
check('оплативший и отказавшийся — в «Отказ», не в 🟢', g2.no === 2 && g2.yes === 1,
  'отказ=' + g2.no + ', подтвердили=' + g2.yes);
check('отказавшийся майский не даёт оборота', g2.revWeek === 100, 'revWeek=' + g2.revWeek);
check('«Потенциал» не тянет отказавшихся', R2.T.revWeekAll === 100, 'все=' + R2.T.revWeekAll);

// ---- корзины не пересекаются: иначе «Молчат» уезжает в минус ----
const R3 = run([les('Лепка №1', [
  { n: 'Думает Дима', may: true, vbReply: 'maybe' },   // оплатил, но «думает» — считаем подтверждённым
  { n: 'Просто Пётр', vbReply: 'maybe' },
  { n: 'Молчит Мария' },
  { n: 'Отказ Оксана', no: true },
])]);
const g3 = R3.rows[0];
check('оплативший с ответом «думаю» считается один раз', g3.yes === 1 && g3.maybe === 1,
  'yes=' + g3.yes + ', maybe=' + g3.maybe);
check('«Молчат» не уходит в минус', g3.none === 1, 'none=' + g3.none);
check('сумма корзин равна числу записей',
  g3.yes + g3.no + g3.maybe + g3.none === g3.total, 'total=' + g3.total);

// ---- группа без вместимости и без цены не должна ломать расчёт ----
const R4 = run([les('Пустая №1', [{ n: 'Кто-то Кто', may: true }])],
  [{ name: 'Лепка', groupSize: 0, price: 0, visits: 0 }]);
check('нет цены и вместимости — нули, а не NaN',
  R4.rows[0].fill === 0 && R4.rows[0].revWeek === 0 && !isNaN(R4.T.revWeekYes));

// ---- дедуп внутри группы и тёзки ----
const R5 = run([les('Лепка №1', [
  { n: 'Иванов Иван', may: true },
  { n: 'Иванов Иван' },                                 // тот же ребёнок второй строкой
  { n: 'Полонников Даниил Ильич', vbReply: 'yes' },
  { n: 'Полонников Даниил Петрович', no: true },        // тёзка — другой ребёнок
])]);
check('дубль внутри группы схлопывается, флаг 💰 сохраняется',
  R5.rows[0].total === 3 && R5.rows[0].yes === 2, 'total=' + R5.rows[0].total + ', yes=' + R5.rows[0].yes);
check('тёзки не сливаются', R5.rows[0].no === 1);

// ---- главное: два блока на ОДНОМ экране должны говорить одно и то же ----
// Верхняя полоса «Набора» берёт confirmed из _goalsFunnel, таблица «Движение по группам» — из
// _ownerReport. Пока правила расходились, на одной странице стояло «Подтвердили 3/4» и тут же
// «🟢 1». Считаем обе функции по одной сетке и сверяем.
const mf = html.match(/\nfunction _goalsFunnel\(\)\s*\{[\s\S]*?\n\}/m);
if (!mf) { console.log('не найдено: _goalsFunnel'); process.exit(1); }
const GRID = [
  les('Лепка №1', [{ n: 'Майский Матвей', may: true }, { n: 'Ответил Артём', vbReply: 'yes' },
                   { n: 'Молчит Мария' }, { n: 'Отказ Оксана', vbReply: 'no' }]),
  les('Лепка №2', [{ n: 'Передумал Пётр', may: true, no: true }, { n: 'Думает Дима', vbReply: 'maybe' }]),
];
GRID[1].group = 'Лепка №2';
const ctxF = {
  S: { grid: GRID },
  kidsOf: l => (Array.isArray(l.kids) ? l.kids : []),
  _nameKey: n => String(n || '').trim().toLowerCase().replace(/\s+/g, ' '),
};
const F = new Function('ctx', 'with (ctx) { ' + mf[0] + '\n return _goalsFunnel(); }')(ctxF);
const RC = run(GRID);

console.log('\nДва блока на одном экране:');
check('«подтвердили» совпадает в отчёте и в воронке', RC.T.yes === F.confirmed,
  'отчёт=' + RC.T.yes + ', воронка=' + F.confirmed);
check('«отказ» совпадает', RC.T.no === F.refused, 'отчёт=' + RC.T.no + ', воронка=' + F.refused);
check('«думают» совпадает', RC.T.maybe === F.maybeN, 'отчёт=' + RC.T.maybe + ', воронка=' + F.maybeN);
check('«молчат» совпадает', RC.T.none === F.silent, 'отчёт=' + RC.T.none + ', воронка=' + F.silent);
check('число записей совпадает', RC.T.kids === F.records, 'отчёт=' + RC.T.kids + ', воронка=' + F.records);

// ---- деньги на «Реальном обороте»: отказавшийся майский не платит ----
const mm = html.match(/\nfunction _mayCountForCourse\([^)]*\)\s*\{[\s\S]*?\n\}/m);
if (!mm) { console.log('не найдено: _mayCountForCourse'); process.exit(1); }
const mayCount = grid => new Function('ctx',
  'with (ctx) { ' + mm[0] + '\n return _mayCountForCourse("Лепка"); }')({
    S: { grid },
    kidsOf: l => (Array.isArray(l.kids) ? l.kids : []),
    _nameKey: n => String(n || '').trim().toLowerCase().replace(/\s+/g, ' '),
  });

console.log('\nМайские в реальном обороте:');
check('оплативший и пришедший считается', mayCount([les('Лепка №1', [{ n: 'Честный Харитон', may: true }])]) === 1);
check('оплативший с ❌ не считается',
  mayCount([les('Лепка №1', [{ n: 'Крестик Кирилл', may: true, no: true }])]) === 0);
check('оплативший с ответом «отказ» не считается',
  mayCount([les('Лепка №1', [{ n: 'Передумал Пётр', may: true, vbReply: 'no' }])]) === 0);
check('оплативший с ответом «думаю» считается',
  mayCount([les('Лепка №1', [{ n: 'Думает Дима', may: true, vbReply: 'maybe' }])]) === 1);
check('один ребёнок в двух группах курса — один майский',
  mayCount([les('Лепка №1', [{ n: 'Иванов Иван', may: true }]),
            les('Лепка №2', [{ n: 'Иванов Иван', may: true }])]) === 1);

console.log(bad ? ('\nПРОВАЛЕНО проверок: ' + bad) : '\nВсе проверки прошли.');
process.exit(bad ? 1 : 0);
