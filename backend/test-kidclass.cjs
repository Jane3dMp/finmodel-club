// Возврат или новый набор: _kidClass. Запуск: node backend/test-kidclass.cjs
//
// 20.08.2026 Жанна: «почему этот ребёнок попал в новых?» (Дорошков Марсель) и
// следом «давай лидов тоже считать как новых». Лид в Альфе — карточка заведена
// (заявка из Instagram/amo, «назначено пробное»), но ребёнок не оформлен и ни
// разу не занимался. Дата такой карточки может быть прошлогодней — считать по
// ней ребёнка вернувшимся нельзя.
//
// Проверяется НАСТОЯЩИЙ код из index.html: функция вырезается из файла как есть.
const fs = require('fs');
const path = require('path');

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const m = html.match(/\nfunction _kidClass\([^)]*\)\s*\{[\s\S]*?\n\}/m);
if (!m) { console.log('не найдено: _kidClass'); process.exit(1); }
const S = { newIncludeUnmatched: true };
const _kidClass = new Function('S', m[0] + '\nreturn _kidClass;')(S);

const CUT = '2026-07-01';
const cls = o => _kidClass(o, CUT);

// --- лид: не оформлен и не занимался → всегда новый, какой бы ни была дата карточки
check('лид с прошлогодней карточкой — новый',
  cls({ alfaId: 5192, study: 0, created: '2025-11-03' }) === 'new');
check('лид со свежей карточкой — новый',
  cls({ alfaId: 5192, study: 0, created: '2026-08-18' }) === 'new');
check('лид без даты — новый',
  cls({ alfaId: 5192, study: 0, created: '' }) === 'new');
check('лид из amo — новый',
  cls({ alfaId: 5192, study: 0, amo: true, created: '2025-02-01' }) === 'new');

// --- но факт занятий перевешивает статус: статус в Альфе могли просто не обновить
check('лид, но проверен по посещениям — возврат',
  cls({ alfaId: 5192, study: 0, prevAttend: true, created: '2025-11-03' }) === 'old');
check('лид, но посещал до отсечки — возврат',
  cls({ alfaId: 5192, study: 0, lastAttend: '2026-05-20' }) === 'old');

// --- клиент: как и раньше, делим по дате заведения карточки
check('клиент с карточкой до отсечки — возврат',
  cls({ alfaId: 100, study: 1, created: '2025-09-01' }) === 'old');
check('клиент с карточкой после отсечки — новый',
  cls({ alfaId: 100, study: 1, created: '2026-08-01' }) === 'new');
check('клиент, посещал до отсечки — возврат',
  cls({ alfaId: 100, study: 1, lastAttend: '2026-04-10', created: '2026-08-01' }) === 'old');

// --- статус неизвестен (выгрузку ещё не делали) — поведение прежнее
check('без статуса, карточка до отсечки — возврат',
  cls({ alfaId: 100, study: null, created: '2025-09-01' }) === 'old');
check('без статуса, карточка после отсечки — новый',
  cls({ alfaId: 100, study: null, created: '2026-08-05' }) === 'new');
check('в Альфе, но дат нет — новый',
  cls({ alfaId: 100, study: null, created: '' }) === 'new');

// --- вовсе не сопоставленные
check('из amo, в Альфе нет — новый',
  cls({ alfaId: null, amo: true }) === 'new');
check('ни Альфы, ни amo — новый (галочка включена)',
  cls({ alfaId: null, amo: false }) === 'new');
S.newIncludeUnmatched = false;
check('ни Альфы, ни amo — не сопоставлен (галочка снята)',
  cls({ alfaId: null, amo: false }) === 'unknown');
S.newIncludeUnmatched = true;

// граница отсечки: сам день отсечки — уже новый набор
check('карточка ровно в день отсечки — новый',
  cls({ alfaId: 100, study: 1, created: CUT }) === 'new');
check('посещение ровно в день отсечки — не возврат',
  cls({ alfaId: 100, study: 1, lastAttend: CUT, created: CUT }) === 'new');

console.log(bad ? ('\nПРОВАЛЕНО проверок: ' + bad) : '\nВсе проверки прошли.');
process.exit(bad ? 1 : 0);
