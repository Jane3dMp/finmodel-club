// «Касса сегодня»: время последнего обновления и поведение кнопки «Обновить».
// Запуск: node backend/test-kassa-fresh.cjs
//
// Жалоба владельца: «нажимаю, а обновление постоянно старое, двигается на 1 минуту всего».
// Две причины, обе проверяются здесь:
//   1) метку времени печатали, отрезая символы от строки. В хранилище лежат ДВЕ разные метки —
//      серверная date('c') со смещением пояса и браузерная toISOString() в UTC. Вторая при таком
//      выводе показывала время на три часа назад: свежий пересчёт выглядел «старым».
//   2) сбой запроса уходил в console.warn: карточка оставалась со старым снимком и старым
//      временем, и нажатие «Обновить» было неотличимо от бездействия.
//
// Часовой пояс закрепляем — иначе тест зависел бы от машины, на которой запущен.
process.env.TZ = 'Europe/Minsk';
const fs = require('fs');
const path = require('path');
const NL = String.fromCharCode(10);

let bad = 0;
function check(name, ok, detail) {
  if (!ok) bad++;
  console.log((ok ? '  ok   ' : ' ПЛОХО ') + name + (ok || !detail ? '' : ': ' + detail));
}
function eq(name, got, want) { check(name, got === want, JSON.stringify(got) + ' ≠ ' + JSON.stringify(want)); }

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
let src = '';
function grab(name) {
  const re = new RegExp('\\n(?:async )?function ' + name + '\\([^)]*\\)\\s*\\{[\\s\\S]*?\\n\\}', 'm');
  const m = html.match(re);
  if (!m) { console.log('не найдено в index.html: ' + name); process.exit(1); }
  src += m[0] + '\n';
}
function grabOne(name) {                    // однострочная функция: закрывается на своей же строке
  const line = html.split(NL).find(x => x.indexOf("function " + name + "(") === 0);
  if (!line) { console.log("не найдено в index.html: " + name); process.exit(1); }
  src += line + NL;
}
['_tsHM', '_tsDMYHM', '_tsAgo', '_tsFreshHtml'].forEach(grab);
grabOne('_kassaPaint');
grab('kassaToday');

/* ================= 1. время снимка ================= */
const ctx0 = {};
const T = new Function('ctx', 'with (ctx) { ' + src.split('\nasync function kassaToday')[0] +
  ' return {_tsHM,_tsDMYHM,_tsAgo,_tsFreshHtml}; }')(ctx0);

// один и тот же момент, записанный двумя способами — и cron, и браузером
eq('серверная метка со смещением', T._tsHM('2026-09-04T14:12:40+03:00'), '14:12');
eq('браузерная метка в UTC', T._tsHM('2026-09-04T11:12:40.000Z'), '14:12');
check('обе метки об одном моменте показывают одно время',
      T._tsHM('2026-09-04T14:12:40+03:00') === T._tsHM('2026-09-04T11:12:40.000Z'));
check('старое отрезание символов больше не показывается',
      T._tsHM('2026-09-04T11:12:40.000Z') !== '11:12');
eq('дата и время вместе', T._tsDMYHM('2026-09-04T11:12:40.000Z'), '04.09 14:12');
eq('мусор вместо даты не роняет карточку', T._tsHM('—'), '');
eq('и в дате остаётся как есть', T._tsDMYHM(''), '');

/* ================= 2. возраст снимка ================= */
const ago = min => new Date(Date.now() - min * 60000).toISOString();
eq('только что', T._tsAgo(ago(0)), 'только что');
eq('семь минут', T._tsAgo(ago(7)), '7 мин назад');
eq('три часа', T._tsAgo(ago(180)), '3 ч назад');
eq('двое суток', T._tsAgo(ago(60 * 24 * 2 + 5)), '2 дн назад');

const fresh = T._tsFreshHtml(ago(2));
check('у свежего снимка возраст виден', fresh.indexOf('2 мин назад') > 0, fresh);
check('и он не подсвечен тревожным', fresh.indexOf('terra') < 0, fresh);
const stale = T._tsFreshHtml(ago(120));
check('у залежавшегося возраст подсвечен', stale.indexOf('terra') > 0, stale);
check('и сказано, насколько он старый', stale.indexOf('2 ч назад') > 0, stale);
eq('без метки — ничего не пишем', T._tsFreshHtml(''), '');

/* ================= 3. кнопка «Обновить» ================= */
const OLD = { income: 3020, count: 21, byIn: { 'ЕРИП': 148 }, byOut: {},
              ts: '2026-09-06T12:23:00+03:00' };
function makeCtx(pubCall) {
  const ctx = {
    _paySnap: { '2026-09-06': OLD },
    _kassaBusy: false, _admSec: 'kassa',
    _kassaAutoDone: false, _kassaTodayBusy: false, _kassaTodayErr: '',
    _todayIso: () => '2026-09-06',
    _pubCall: pubCall,
    _pubErrHtml: (e, where) => '<!--ошибка:' + where + ':' + ((e && e.message) || e) + '-->',
    _kassaHtml: () => '<div>карточка</div>',
    paints: 0,
  };
  ctx.document = { getElementById: id => (id === 'admBody' ? { set innerHTML(v) { ctx.paints++; } } : null) };
  const api = new Function('ctx', 'with (ctx) { ' + src + ' return {kassaToday}; }')(ctx);
  return { ctx, kassaToday: api.kassaToday };
}

(async () => {
  /* --- удачный пересчёт --- */
  {
    const { ctx, kassaToday } = makeCtx(async () => ({ ok: true, snapshot: { income: 3300, count: 23, byIn: {}, byOut: {} } }));
    await kassaToday(true);
    eq('снимок обновился', ctx._paySnap['2026-09-06'].count, 23);
    check('метка времени — разбираемая дата', !isNaN(new Date(ctx._paySnap['2026-09-06'].ts).getTime()));
    check('метка времени — свежая',
          Math.abs(Date.now() - new Date(ctx._paySnap['2026-09-06'].ts).getTime()) < 5000);
    eq('ошибки нет', ctx._kassaTodayErr, '');
    eq('кнопка отпущена', ctx._kassaTodayBusy, false);
    check('карточка перерисована', ctx.paints >= 2, 'перерисовок: ' + ctx.paints);
  }

  /* --- запрос не дошёл: раньше об этом молчали --- */
  {
    const { ctx, kassaToday } = makeCtx(async () => { throw new Error('Хостинг не пропустил запрос'); });
    await kassaToday(true);
    check('ошибка показана в карточке', ctx._kassaTodayErr.indexOf('Хостинг не пропустил') > 0, ctx._kassaTodayErr);
    eq('старые цифры не подменены', ctx._paySnap['2026-09-06'].count, 21);
    eq('и старая метка тоже на месте', ctx._paySnap['2026-09-06'].ts, OLD.ts);
    eq('кнопка отпущена даже после сбоя', ctx._kassaTodayBusy, false);
  }

  /* --- Alfa ответила, но снимок не собрался --- */
  {
    const { ctx, kassaToday } = makeCtx(async () => ({ ok: true, snapshot: null }));
    await kassaToday(true);
    check('про несобранный снимок сказано', ctx._kassaTodayErr.indexOf('не собрался') > 0, ctx._kassaTodayErr);
    eq('цифры остались прежними', ctx._paySnap['2026-09-06'].count, 21);
  }

  /* --- второе нажатие поверх работающего запроса --- */
  {
    let calls = 0, release;
    const { ctx, kassaToday } = makeCtx(() => { calls++; return new Promise(r => { release = r; }); });
    const p = kassaToday(true);
    await kassaToday(true);                    // пока первый запрос в воздухе
    eq('второй запрос не ушёл', calls, 1);
    check('кнопка заблокирована на время работы', ctx._kassaTodayBusy === true);
    release({ ok: true, snapshot: { income: 1, count: 1, byIn: {}, byOut: {} } });
    await p;
    eq('после ответа кнопка свободна', ctx._kassaTodayBusy, false);
  }

  /* --- автозапуск при открытии вкладки не повторяется, а кнопка работает всегда --- */
  {
    let calls = 0;
    const { ctx, kassaToday } = makeCtx(async () => { calls++; return { ok: true, snapshot: { income: 0, count: 0, byIn: {}, byOut: {} } }; });
    await kassaToday();                        // снимок за сегодня уже есть — сам не лезет
    eq('автозапуск при готовом снимке молчит', calls, 0);
    await kassaToday(true);                    // а по кнопке идёт
    eq('по кнопке пересчитал', calls, 1);
    await kassaToday(true);
    eq('и повторно тоже', calls, 2);
  }

  /* ================= 4. как это видно на карточке ================= */
  {
    const m = html.match(/\nfunction _kassaTopHtml\(\)[\s\S]*?\n\}/m);
    if (!m) { console.log('не найдено: _kassaTopHtml'); process.exit(1); }
    const ctx = {
      _paySnap: { '2026-09-06': OLD }, _kassaDate: '2026-09-06', _kassaBusy: false,
      _kassaTodayBusy: true, _kassaTodayErr: '<!--боль-->',
      _todayIso: () => '2026-09-06',
      _RU_MON: ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'],
      _gm: n => String(Math.round(+n || 0)),
      esc: x => String(x),
      _kassaNetTable: () => '<table></table>', _kassaMonth: () => null,
      _kassaDMY: iso => iso, _kassaDayWord: () => 'дней', _kassaCashCard: () => '',
      _tsFreshHtml: T._tsFreshHtml,
    };
    const top = new Function('ctx', 'with (ctx) { ' + m[0] + ' return _kassaTopHtml; }')(ctx)();
    check('во время пересчёта кнопка заблокирована', top.indexOf('disabled') > 0);
    check('и подписана «считаю»', top.indexOf('Считаю из Alfa') > 0);
    check('и сказано, почему это долго', top.indexOf('до минуты') > 0);
    check('ошибка выводится на карточку', top.indexOf('<!--боль-->') > 0);
    check('время обновления — местное', top.indexOf('обновлено 12:23') > 0,
          top.slice(Math.max(0, top.indexOf('операций:')), top.indexOf('операций:') + 160));

    // за сегодня цифр так и не появилось, а пересчёт отвалился: обещать «считаю» рядом с
    // ошибкой нельзя — это читается как «всё идёт по плану»
    ctx._paySnap = {};
    ctx._kassaTodayBusy = false;
    const fail = new Function('ctx', 'with (ctx) { ' + m[0] + ' return _kassaTopHtml; }')(ctx)();
    check('после сбоя карточка не обещает «считаю»', fail.indexOf('Считаю из Alfa') < 0);
    check('и говорит, что цифр нет', fail.indexOf('пересчёт не прошёл') > 0);
    ctx._kassaTodayErr = '';
    const wait = new Function('ctx', 'with (ctx) { ' + m[0] + ' return _kassaTopHtml; }')(ctx)();
    check('а без ошибки — честное «считаю»', wait.indexOf('Считаю из Alfa') > 0);
  }

  console.log(bad ? ('\n❌ провалов: ' + bad) : '\n✅ всё сошлось');
  process.exit(bad ? 1 : 0);
})();
