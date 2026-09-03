// Печать списка детей по статусу из «Движения по группам».
// Запуск: node backend/test-goals-print.cjs
//
// Проверяется НАСТОЯЩИЙ код из index.html — функции вырезаются из файла и исполняются.
// Главное, что здесь проверяется: список «молчат» = ровно те дети, что посчитаны
// в колонке «Молчат» таблицы. Разойдётся — Жанна обзвонит не тех.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const NAMES = ['_ownerReport', '_goalKidSt', '_goalPrintRows', '_goalPrintFormHtml',
               '_agePrint', '_ageYM', '_dobIso', '_childRec'];
const ONE_LINERS = ['_goalStLab', '_goalPrintPicked', '_courseEcoOf', 'kidsOf', '_yearsWord'];
const CONSTS = ['GOAL_ST'];
let src = '';
function grab(name, re) {
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
for (const n of CONSTS)     grab(n, new RegExp('\\nconst ' + n + '=.*\\n', 'm'));
for (const n of NAMES)      grab(n, new RegExp('\\nfunction ' + n + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm'));
for (const n of ONE_LINERS) grab(n, new RegExp('\\nfunction ' + n + '\\(.*\\}$', 'm'));

/* --- расписание: две группы, дети в разных статусах ---
   Аня: молчит в обеих группах (одна строка, две группы)   Боря: оплатил майский → подтвердил
   Вера: ответила «думает»                                  Гоша: ❌ не придёт → отказ
   Даша: ответила «да»                                      Егор: молчит, приглашение не отправляли
   Жора: оплатил майский, но потом отказался → отказ (отказ перевешивает оплату) */
const kid = (n, o) => Object.assign({ n: n, may: false, no: false, vbSent: true, vbReply: '', phone: '', dob: '', alfaId: null }, o || {});
const GRID = [
  { id: 'l1', course: 'Roblox', group: 'Roblox №1', teacher: 'Козырев Влад', day: 1, start: '09:30', kids: [
    kid('Иванова Аня', { phone: '+375291112233', dob: '2017-03-15' }),
    kid('Петров Боря', { may: true, phone: '+375292223344', dob: '2015-06-01' }),
    kid('Сидорова Вера', { vbReply: 'maybe', phone: '+375293334455', dob: '2016-01-20' }),
    kid('Кузнецов Гоша', { no: true, phone: '+375294445566', dob: '2016-09-09' }),
    kid('Носов Жора', { may: true, vbReply: 'no', phone: '+375297778899', dob: '2015-02-02' }),
  ] },
  { id: 'l2', course: 'Английский язык', group: 'Английский №1', teacher: 'Бурдук Наталья', day: 2, start: '10:00', kids: [
    kid('Иванова Аня', { alfaId: 77, vbSent: false }),         // та же Аня: телефон и ДР — из S.children,
                                                               // и приглашение отправлено только в Roblox
    kid('Орлова Даша', { vbReply: 'yes', phone: '+375295556677', dob: '2014-11-30' }),
    kid('Егоров Егор', { vbSent: false, phone: '+375296667788', dob: '2018-05-05' }),
  ] },
];

const ctx = {
  S: { grid: GRID, assume: { weeksPerMonth: 4 },
       children: { '77': { phone: '+375290000000', dob: '2017-03-15' } } },
  courseFromLib: c => ({ groupSize: 8, price: 25, visits: 1, eco: c === 'Roblox' ? 'CODDY' : 'Прознание' }),
  _goalsEco: '',
  _glPrint: { st: { none: 1 }, unsent: false, mark: true },
  esc: s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  toast: () => {}, _copyText: () => Promise.resolve(true),
  document: { getElementById: () => null },
  // однострочные с хвостовым комментарием — регуляркой не вырезаются, повторяем поведение
  _nameKey: s => String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(),
  window: { open: () => null },
  setTimeout: () => {},
};
const API = new Function('ctx', 'with (ctx) { ' + src +
  ' return {_ownerReport,_goalKidSt,_goalPrintRows,_goalPrintFormHtml,_agePrint,' +
  ' setEco:v=>{_goalsEco=v}, setPrint:v=>{_glPrint=v}}; }')(ctx);

/* ================= статус записи ================= */
eq('молчит', API._goalKidSt({ reply: '' }), 'none');
eq('оплатил майский = подтвердил', API._goalKidSt({ may: true, reply: '' }), 'yes');
eq('ответил «да»', API._goalKidSt({ reply: 'yes' }), 'yes');
eq('думает', API._goalKidSt({ reply: 'maybe' }), 'maybe');
eq('крестик «не придёт» = отказ', API._goalKidSt({ no: true }), 'no');
eq('отказ перевешивает оплату майского', API._goalKidSt({ may: true, reply: 'no' }), 'no');

/* ================= список совпадает с таблицей =================
   Это и есть смысл теста: сумма по колонкам «Молчат»/«🟢»/«🔴»/«🟡» должна давать
   ровно тех детей, что попадут в список. */
const { rows, T } = API._ownerReport();
eq('групп в таблице', rows.length, 2);
eq('таблица: молчат', T.none, 3);          // Аня ×2 записи + Егор
eq('таблица: подтвердили', T.yes, 2);      // Боря (майский) + Даша
eq('таблица: отказ', T.no, 2);             // Гоша + Жора
eq('таблица: думают', T.maybe, 1);         // Вера

const silent = API._goalPrintRows();
eq('в списке «молчат» — уникальных детей', silent.length, 2);   // Аня одна строка, хоть и 2 группы
eq('первым по алфавиту', silent[0].name, 'Егоров Егор');
const anya = silent.find(r => r.name === 'Иванова Аня');
eq('у Ани обе группы в одной строке', anya.groups.length, 2);
eq('группа названа как в таблице', anya.groups[0].name, 'Roblox №1');
eq('день и время подставлены', anya.groups[0].when, 'Пн 09:30');
eq('телефон взят из записи в сетке', anya.phone, '+375291112233');
eq('возраст есть', API._agePrint(anya.dob).length > 0, true);

// у второй записи Ани телефона нет, зато есть связь с Альфой — контакт из S.children
ctx.S.grid[0].kids[0].phone = ''; ctx.S.grid[0].kids[0].dob = '';
const anya2 = API._goalPrintRows().find(r => r.name === 'Иванова Аня');
eq('телефон подтянулся из карточки ребёнка (S.children)', anya2.phone, '+375290000000');
eq('дата рождения тоже', anya2.dob, '2017-03-15');
ctx.S.grid[0].kids[0].phone = '+375291112233'; ctx.S.grid[0].kids[0].dob = '2017-03-15';

/* ================= выбор статусов ================= */
API.setPrint({ st: { yes: 1 }, unsent: false, mark: true });
eq('подтвердившие', API._goalPrintRows().map(r => r.name).sort().join(', '), 'Орлова Даша, Петров Боря');
API.setPrint({ st: { no: 1 }, unsent: false, mark: true });
eq('отказавшиеся (включая оплатившего майский)', API._goalPrintRows().map(r => r.name).sort().join(', '),
   'Кузнецов Гоша, Носов Жора');
API.setPrint({ st: { none: 1, maybe: 1 }, unsent: false, mark: true });
eq('молчат + думают — с кем предстоит работать', API._goalPrintRows().length, 3);
API.setPrint({ st: {}, unsent: false, mark: true });
eq('не выбран ни один статус — пусто', API._goalPrintRows().length, 0);

/* ================= «кому не отправляли» ================= */
API.setPrint({ st: { none: 1 }, unsent: true, mark: true });
const unsent = API._goalPrintRows();
eq('не отправляли — Аня и Егор', unsent.map(r => r.name).sort().join(', '), 'Егоров Егор, Иванова Аня');
// фильтр работает по ЗАПИСИ, а не по ребёнку: у Ани в Roblox отправлено, в Английском нет —
// в списке остаётся только вторая группа, чтобы не звонить по той, где уже написали
eq('у Ани осталась одна группа — та, где не отправляли',
   unsent.find(r => r.name === 'Иванова Аня').groups.length, 1);
eq('и это Английский', unsent.find(r => r.name === 'Иванова Аня').groups[0].name, 'Английский №1');

/* ================= фильтр направления ================= */
API.setPrint({ st: { none: 1, yes: 1, no: 1, maybe: 1 }, unsent: false, mark: true });
eq('без фильтра — все дети', API._goalPrintRows().length, 7);
API.setEco('CODDY');
eq('только CODDY (Roblox)', API._goalPrintRows().length, 5);
API.setEco('Прознание');
eq('только Прознание (английский)', API._goalPrintRows().map(r => r.name).sort().join(', '),
   'Егоров Егор, Иванова Аня, Орлова Даша');
API.setEco('');

/* ================= возраст =================
   Пишем словами: «9 лет 5 мес» с листа читается, «9 л 5 м» — нет. */
const y = n => { const d = new Date(); return (d.getFullYear() - n) + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
eq('ровно 12 лет — без месяцев', API._agePrint(y(12)), '12 лет');
eq('склонение: 4 года', API._agePrint(y(4)), '4 года');
eq('склонение: 1 год', API._agePrint(y(1)), '1 год');
check('месяцы дописываются', /^\d+ (год|года|лет) \d+ мес$/.test(API._agePrint('2017-03-15')),
      API._agePrint('2017-03-15'));
eq('без даты рождения — пусто', API._agePrint(''), '');

/* ================= окно выбора ================= */
API.setPrint({ st: { none: 1 }, unsent: false, mark: true });
const form = API._goalPrintFormHtml();
check('в окне видно, сколько детей попадёт', form.indexOf('Попадёт детей: <b>2</b>') > 0);
check('все четыре статуса предложены', GOAL_STcount(form) === 4);
function GOAL_STcount(h) { return (h.match(/type="checkbox"/g) || []).length - 2; }  // минус «не отправляли» и «отметки»
check('кнопка печати с числом', form.indexOf('🖨 Печать (2)') > 0);
// .modal input{width:100%} растягивал галочку на всю строку и текст уезжал вправо
check('галочки не растянуты на всю ширину модалки', form.indexOf('style="width:auto;flex:none;margin:0"') > 0);
API.setPrint({ st: {}, unsent: false, mark: true });
check('без выбранных статусов печать заблокирована', API._goalPrintFormHtml().indexOf('disabled onclick="goalPrintList()"') > 0);

console.log(bad ? '\n❌ провалено проверок: ' + bad : '\n✅ всё сошлось');
process.exit(bad ? 1 : 0);
