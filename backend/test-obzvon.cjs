// Списки на обзвон (Дашборд Администратора → Списки на обзвон).
// Запуск: node backend/test-obzvon.cjs
//
// Жанна: «кто не дошёл из новых — с балансами и контактами, для менеджера. И второй список —
// кто не пришёл на какой курс из прошлогодних, т.е. все остальные; их обзванивают админы».
//
// Главное, что проверяется:
//   • «не дошёл из новых» — занятия ПРОШЛИ и ни на одном не был; будущие занятия ничего не
//     меняют (ровно та же ловушка, что уже ловилась в воронке по набору);
//   • прошлогодние считаются ПО КУРСАМ: ходит на Roblox, ни разу не был на Scratch — звонить
//     нужно про Scratch, и это должно быть видно;
//   • никто не теряется: «новый» по таблице, у которого Alfa показала занятия до 1 сентября,
//     уходит во второй список, а не пропадает между списками;
//   • в печати есть то, ради чего списки и печатают: телефон, баланс, ЭВ 26/27.
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
let bad = 0;
const t = (n, c, d) => { if (!c) bad++; console.log((c ? 'ok   ' : 'FAIL ') + n + (c || !d ? '' : ': ' + d)); };
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const TODAY = '2026-09-13';
const SUBJ = { 7: 'Roblox', 9: 'Scratch', 11: 'Пескография' };

/* снимок в том виде, в каком его кладёт cron_obzvon.php */
function snap(o) {
  return {
    builtAt: '2026-09-13T21:00:04+03:00', ranBy: 'cron',
    season: '2026-09-01', from: '2025-09-01', to: '2026-10-13', today: TODAY,
    roster: { new: o.new || [], old: o.old || [] },
    kids: o.kids || {}, before: o.before || {}, cards: o.cards || {}, tar: o.tar || {},
    subjects: SUBJ, teachers: {},
  };
}
function build(sn) {
  const scope = {
    esc,
    _obz: sn, _obzMeta: null, _obzBusy: false, _obzProg: '', _obzErr: '',
    _obzRosterSent: '', _obzLoaded: true, _admSec: 'obzvon',
    _trToday: () => TODAY,
    _trPhone: () => '',                       // в модели телефона нет — берём тот, что в росписи
    _trKidLink: (id, nm) => '<a>' + esc(nm || id) + '</a>',
    _goalsKidLists: () => ({ newKids: [], oldKids: [] }),
    document: { getElementById: () => null },
    toast: () => {},
    _jsStr: (x) => String(x).split(BS).join(BS + BS).split(Q).join(BS + Q),
    Date, String, Number, Object, Math, Array, JSON,
  };
  const body = grab('_obzCard') + grab('_obzArch') + grab('_obzEv') + grab('_obzName') + grab('_obzSubj')
    + grab('_obzPhone') + grab('_obzTar') + grab('_obzTarText') + grab('_obzBalance') + grab('_obzDay')
    + grab('_trLesState') + grab('_obzNewMissed') + grab('_obzOldMissed') + grab('_obzWhen')
    + grab('_obzNewTable') + grab('_obzOldTable') + grab('_obzRoster') + grab('_obzRosterFp')
    + grab('_obzRowsHtml') + grab('_obzvonHtml')
    + 'const OBZ_CSS="";'
    // таблицы строим только при снимке — ровно как obzPrintNew/obzPrintOld, которые сперва
    // проверяют, что собирать есть из чего
    + '; var a=_obzNewMissed(), b=_obzOldMissed();'
    + ' return {a:a, b:b, tblA:a?_obzNewTable(a):"", tblB:b?_obzOldTable(b):"",'
    + ' sec:_obzvonHtml(), fp:_obzRosterFp, roster:_obzRoster};';
  return new Function(...Object.keys(scope), body)(...Object.values(scope));
}
const les = (date, done, sum, attend, subjectId) =>
  ({ date, done, sum, attend, subjectId: subjectId || 7, from: '17:30', to: '18:30', teacherIds: [] });
const kid = (id, name, phone) => ({ id, name, phone: phone || '' });

console.log('--- 1. СПИСОК 1: не дошли из новых ---');
let r = build(snap({
  new: [kid(1, 'Аня'), kid(2, 'Боря'), kid(3, 'Витя'), kid(4, 'Гриша'), kid(5, 'Даша')],
  kids: {
    1: [les('2026-09-05', true, 0, null), les('2026-09-12', true, 0, null)],          // ни разу не был
    2: [les('2026-09-05', true, 15, true), les('2026-09-12', true, 0, null)],         // был, потом пропустил
    3: [les('2026-09-20', false, null, null)],                                        // первое ещё впереди
    4: [les('2026-09-05', true, 0, null), les('2026-09-20', false, null, null)],      // пропустил, но записан дальше
    5: [],                                                                            // занятий нет вовсе
  },
}));
t('не дошли — двое (Аня и Гриша)', r.a.length === 2, r.a.map(x => x.name).join(','));
t('тот, кто был хоть раз, в список не попадает', !r.a.some(x => x.name === 'Боря'));
t('тот, у кого первое занятие впереди, — не потеря', !r.a.some(x => x.name === 'Витя'));
t('ГЛАВНОЕ: занятие впереди не выводит из «не дошёл»', r.a.some(x => x.name === 'Гриша'));
t('ребёнок без занятий в обзвон не идёт', !r.a.some(x => x.name === 'Даша'));
t('у Гриши видно, что впереди ещё есть', (r.a.find(x => x.name === 'Гриша') || {}).ahead.length === 1);
t('список отсортирован по имени', r.a.map(x => x.name).join(',') === 'Аня,Гриша');

console.log('--- 2. отметка без списания — это «пришёл», а не потеря ---');
r = build(snap({ new: [kid(1, 'Аня')], kids: { 1: [les('2026-09-05', true, 0, true)] } }));
t('0 и отметка — ребёнок пришёл, абонемент не проставили', r.a.length === 0);

console.log('--- 3. архивных и возвращенцев в первый список не берём ---');
r = build(snap({
  new: [kid(1, 'Аня'), kid(2, 'Боря')],
  kids: { 1: [les('2026-09-05', true, 0, null)], 2: [les('2026-09-05', true, 0, null)] },
  cards: { 1: { name: 'Аня Иванова', archived: true, evzz: '' } },
  before: { 2: { n: 12, last: '2026-05-20' } },
}));
t('архивная карточка Alfa — не звоним', !r.a.some(x => x.id === '1'));
t('занимался до 1 сентября — не новый клиент', !r.a.some(x => x.id === '2'));
t('ГЛАВНОЕ: такой не теряется — уходит во второй список', r.b.length === 1 && r.b[0].id === '2');
t('и там объяснено, почему он тут', /до 1 сентября/.test(r.b[0].why), r.b[0].why);

console.log('--- 4. СПИСОК 2: прошлогодние — по КУРСАМ ---');
r = build(snap({
  old: [kid(10, 'Егор'), kid(11, 'Жанна'), kid(12, 'Зина')],
  kids: {
    10: [les('2026-09-05', true, 15, true, 7), les('2026-09-06', true, 0, null, 9),
         les('2026-09-13', true, 0, null, 9)],                    // ходит на Roblox, не пришёл на Scratch
    11: [les('2026-09-05', true, 15, true, 7), les('2026-09-06', true, 15, true, 9)],   // ходит везде
    12: [les('2026-09-05', true, 0, null, 7), les('2026-09-06', true, 0, null, 9)],     // нигде не появился
  },
}));
t('в списке двое', r.b.length === 2, r.b.map(x => x.name).join(','));
t('Егор — один потерянный курс', (r.b.find(x => x.name === 'Егор') || {}).lost.length === 1);
t('и это именно Scratch', SUBJ[(r.b.find(x => x.name === 'Егор') || {}).lost[0].subjectId] === 'Scratch');
t('ГЛАВНОЕ: видно, куда он всё-таки ходит', (r.b.find(x => x.name === 'Егор') || {}).goes.join(',') === 'Roblox');
t('кто ходит на всё — в список не попадает', !r.b.some(x => x.name === 'Жанна'));
t('Зина потеряла оба курса', (r.b.find(x => x.name === 'Зина') || {}).lost.length === 2);
t('и про неё видно, что нигде не появилась', (r.b.find(x => x.name === 'Зина') || {}).goes.length === 0);
t('оба пропуска Scratch у Егора сложены в один курс, а не в две строки',
  (r.b.find(x => x.name === 'Егор') || {}).lost[0].miss.length === 2);

console.log('--- 5. будущие занятия сами по себе курс не теряют ---');
r = build(snap({ old: [kid(10, 'Егор')], kids: { 10: [les('2026-09-20', false, null, null, 9)] } }));
t('только будущее занятие — звонить не о чем', r.b.length === 0);

console.log('--- 6. печать: то, ради чего список печатают ---');
r = build(snap({
  new: [kid(1, 'Аня', '+375291112233')],
  old: [kid(10, 'Егор', '+375299998877')],
  kids: {
    1: [les('2026-09-05', true, 0, null, 11)],
    10: [les('2026-09-05', true, 15, true, 7), les('2026-09-06', true, 0, null, 9)],
  },
  cards: { 1: { name: 'Аня Иванова', archived: false, evzz: 'думает до пятницы' },
           10: { name: 'Егор Петров', archived: false, evzz: 'перезвонить маме' } },
  tar: { 1: { tariffs: [{ name: 'Пробное занятие', balance: 0, trial: true }], paid: false },
         10: { tariffs: [{ name: 'Абонемент 8 занятий', balance: 6, trial: false }], paid: true } },
}));
t('в списке менеджера есть телефон', r.tblA.includes('+375291112233'));
t('имя берётся из карточки Alfa, а не из таблицы', r.tblA.includes('Аня Иванова'));
t('видно, что абонемент не куплен', r.tblA.includes('только пробный'));
t('ЭВ 26/27 в печати менеджера', r.tblA.includes('думает до пятницы'));
t('курс, на который не пришли, назван словом', r.tblA.includes('Пескография'));
t('в списке админов есть телефон', r.tblB.includes('+375299998877'));
t('ГЛАВНОЕ: ЭВ 26/27 в печати админов', r.tblB.includes('перезвонить маме'));
t('баланс числом', r.tblB.includes('>6</td>'), r.tblB.slice(r.tblB.indexOf('Егор'), r.tblB.indexOf('Егор') + 400));
t('назван потерянный курс', r.tblB.includes('Scratch'));
t('и курс, куда ребёнок ходит', r.tblB.includes('Roblox'));
t('пустая клетка «дозвон» есть в обоих', r.tblA.includes('class="call"') && r.tblB.includes('class="call"'));
t('в шапке — когда снят снимок', r.tblA.includes('13.09.2026') || r.tblA.includes('2026'));

console.log('--- 7. пустые списки печатаются как пустые, а не ломаются ---');
r = build(snap({ new: [], old: [] }));
t('нет новых — так и написано', r.tblA.includes('дошли все'));
t('нет прошлогодних — так и написано', r.tblB.includes('дошли на все свои курсы'));

console.log('--- 8. отпечаток росписи: одно и то же дважды не шлём ---');
const fp = r.fp;
const R1 = { new: [{ id: 1, phone: '+1' }], old: [{ id: 2, phone: '' }] };
const R2 = { new: [{ id: 1, phone: '+1' }], old: [{ id: 2, phone: '' }] };
const R3 = { new: [{ id: 1, phone: '+375' }], old: [{ id: 2, phone: '' }] };
const R4 = { new: [{ id: 1, phone: '+1' }], old: [] };
t('роспись не изменилась — отпечаток тот же', fp(R1) === fp(R2));
t('поменяли телефон — отпечаток другой', fp(R1) !== fp(R3));
t('ребёнок ушёл из росписи — отпечаток другой', fp(R1) !== fp(R4));

console.log('--- 9. снимка нет — списки не выдумываются ---');
r = build(null);
t('без снимка «не дошли из новых» — null, а не пустой список', r.a === null);
t('без снимка «прошлогодние» — null', r.b === null);
t('раздел не падает и объясняет, что снимка нет', r.sec.includes('Снимка ещё нет'));
t('и зовёт собрать', r.sec.includes('obzBuildNow()'));

console.log('--- 10. сам раздел: кнопки печати доведены до своих функций ---');
// ⚠️ Ловушка, на которую уже попались: у второго блока пропал аргумент, и в кнопку уехали
// не те строки — подпись стала именем функции, а onclick — цветом. Тесты корзин этого не
// видели, потому что рисовалку раздела они не звали.
r = build(snap({
  new: [kid(1, 'Аня', '+375291112233')],
  old: [kid(10, 'Егор', '+375299998877')],
  kids: { 1: [les('2026-09-05', true, 0, null, 11)],
          10: [les('2026-09-05', true, 15, true, 7), les('2026-09-06', true, 0, null, 9)] },
}));
t('кнопка списка менеджера зовёт obzPrintNew', r.sec.includes('onclick="obzPrintNew()"'));
t('кнопка списка админов зовёт obzPrintOld', r.sec.includes('onclick="obzPrintOld()"'));
t('и есть печать обоих сразу', r.sec.includes('onclick="obzPrintBoth()"'));
t('подписи кнопок человеческие, а не имена функций',
  r.sec.includes('печать списка менеджера') && r.sec.includes('печать списка админов'));
t('ГЛАВНОЕ: в разделе нет «undefined» — значит аргументы не съехали',
  !r.sec.includes('undefined'), r.sec.slice(Math.max(0, r.sec.indexOf('undefined') - 120), r.sec.indexOf('undefined') + 60));
t('обе цифры на плитках', r.sec.includes('>1</div>'));
t('видно, когда собран снимок и кем', r.sec.includes('воскресный прогон'));

console.log(bad ? NL + 'ПРОВАЛОВ: ' + bad : NL + 'всё хорошо');
process.exit(bad ? 1 : 0);
