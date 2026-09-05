// Повторы и «шлагбаум» в _apiPost — путь ВСЕХ запросов к прокси Alfa/amo.
// Запуск: node backend/test-apipost.cjs
//
// Зачем: хостинг подставляет антибот-страницу вместо ответа. Раньше было 3 попытки по 6 секунд
// (окно 12 с — короче реальной блокировки), повтора на обрыв связи и 5xx не было вовсе, а
// параллельные разделы шли к прокси одновременно, и одно открытие раздела разворачивалось
// в дюжину запросов подряд.
const fs = require('path') && require('fs');
const path = require('path');
const NL = String.fromCharCode(10);
const src = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

const i = src.indexOf('let _proxyGateUntil=0;');
const j = src.indexOf(NL + '}', src.indexOf('async function _apiPost('));
if (i < 0 || j < 0) { console.log('не найден _apiPost в index.html'); process.exit(1); }
const code = src.slice(i, j + 2);

let bad = 0;
const t = (n, c, d) => { if (!c) bad++; console.log((c ? 'ok   ' : 'FAIL ') + n + (c || !d ? '' : ': ' + d)); };

// окружение: время идёт мгновенно, задержки только записываем
function build(responder) {
  const waits = [];
  const toasts = [];
  let calls = 0;
  const scope = {
    fetch: async () => { calls++; return responder(calls); },
    _apiJson: async (r) => r && r.__json,
    _alfaIdToken: async () => 'tok',
    toast: (m) => toasts.push(m),
    setTimeout: (fn, ms) => { waits.push(ms); fn(); return 0; },
    Math, Date, Promise, JSON, String, Error,
  };
  const api = new Function(...Object.keys(scope), code + '; return {_apiPost, gate: () => _proxyGateUntil, open: _proxyGateOpen};')(...Object.values(scope));
  return { api, waits, toasts, calls: () => calls };
}
const ok = (body) => ({ status: 200, ok: true, __json: body });
const chal = () => ({ status: 200, ok: true, __json: { ok: false, challenge: true, error: 'проверка' } });

(async () => {
  console.log('--- 1. успех с первой попытки ---');
  let b = build(() => ok({ ok: true, v: 1 }));
  let res = await b.api._apiPost('/p/', 'ping', {});
  t('вернулся ответ', res.j.v === 1);
  t('запрос был один', b.calls() === 1, 'было ' + b.calls());
  t('ждать не пришлось', b.waits.length === 0);

  console.log('--- 2. проверка хостинга: повторяем с РАСТУЩЕЙ паузой ---');
  b = build((n) => (n < 3 ? chal() : ok({ ok: true, v: 2 })));
  res = await b.api._apiPost('/p/', 'ping', {});
  t('в итоге получили ответ', res.j.v === 2);
  t('понадобилось 3 попытки', b.calls() === 3, 'было ' + b.calls());
  t('паузы растут, а не фиксированы', b.waits.length === 2 && b.waits[1] > b.waits[0],
    JSON.stringify(b.waits));
  t('первая пауза около 5 секунд', b.waits[0] >= 5000 && b.waits[0] < 7000, String(b.waits[0]));
  t('вторая около 10', b.waits[1] >= 10000 && b.waits[1] < 12000, String(b.waits[1]));
  t('суммарное окно много больше прежних 12 секунд',
    b.waits.reduce((a, x) => a + x, 0) > 14000, String(b.waits.reduce((a, x) => a + x, 0)));

  console.log('--- 3. шлагбаум закрывается для ВСЕХ, а не только для этого запроса ---');
  b = build((n) => (n < 2 ? chal() : ok({ ok: true })));
  const before = Date.now();
  await b.api._apiPost('/p/', 'ping', {});
  t('после успеха шлагбаум снова открыт', b.api.gate() <= before,
    'gate=' + b.api.gate() + ' now=' + Date.now());
  b = build(() => chal());
  await b.api._apiPost('/p/', 'ping', {});
  t('после серии проверок шлагбаум закрыт', b.api.gate() > Date.now());

  console.log('--- 4. обрыв связи тоже повод повторить (раньше не повторялось вовсе) ---');
  let n = 0;
  b = build(() => { n++; if (n < 3) throw new TypeError('Failed to fetch'); return ok({ ok: true, v: 4 }); });
  res = await b.api._apiPost('/p/', 'ping', {});
  t('дошли до ответа', res.j.v === 4);
  t('было три попытки', b.calls() === 3, 'было ' + b.calls());
  t('пользователю сказали про связь', b.toasts.some(x => /связь/i.test(x)), JSON.stringify(b.toasts));

  console.log('--- 5. 500 от сервера — тоже повод повторить ---');
  b = build((k) => (k < 2 ? { status: 500, ok: false, __json: { ok: false, error: 'ответ не JSON (500)' } } : ok({ ok: true, v: 5 })));
  res = await b.api._apiPost('/p/', 'ping', {});
  t('повторили и получили ответ', res.j.v === 5, JSON.stringify(res.j));

  console.log('--- 6. ошибка входа не повторяется — это не сетевой сбой ---');
  const scope2 = {
    fetch: async () => ok({ ok: true }), _apiJson: async r => r.__json,
    _alfaIdToken: async () => { throw new Error('Нужно войти в модель (Firebase).'); },
    toast: () => {}, setTimeout: (fn) => { fn(); return 0; },
    Math, Date, Promise, JSON, String, Error,
  };
  const api2 = new Function(...Object.keys(scope2), code + '; return {_apiPost};')(...Object.values(scope2));
  let threw = null;
  try { await api2._apiPost('/p/', 'ping', {}); } catch (e) { threw = e; }
  t('брошено сразу', threw !== null && /Firebase/.test(threw.message));

  console.log('--- 7. связь рвётся все попытки: понятная ошибка, а не undefined ---');
  b = build(() => { throw new TypeError('Failed to fetch'); });
  threw = null;
  try { await b.api._apiPost('/p/', 'ping', {}); } catch (e) { threw = e; }
  t('брошена внятная ошибка', threw !== null && /связь обрывалась/.test(threw.message),
    threw ? threw.message : 'ничего не брошено');

  if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
  console.log(NL + 'всё сошлось');
})();
