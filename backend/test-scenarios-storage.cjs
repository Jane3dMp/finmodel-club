// Хранение версий модели в localStorage. Запуск: node backend/test-scenarios-storage.cjs
//
// Зачем: у модели на 869 детей в браузере лежало ЧЕТЫРЕ полных копии при трёх версиях —
// открытая хранилась и в LS_KEY, и внутри списка версий. Память переполнялась, а приложение
// при этом МОЛЧА удаляло старую версию, чтобы освободить место, и первой под нож шла та,
// что называется «Не трогать».
const fs = require('fs');
const path = require('path');
const NL = String.fromCharCode(10);
const src = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

function grab(name){
  const i = src.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('не найдена ' + name);
  const j = src.indexOf(NL + '}', i);
  return src.slice(i, j + 2) + NL;
}
const code = grab('_lsUsage') + grab('_lsMb') + grab('_lsWarn') + grab('_lsTrimScenarios')
           + grab('loadScenarioList') + grab('saveScenarioList');

let bad = 0;
const t = (n, c, d) => { if (!c) bad++; console.log((c ? 'ok   ' : 'FAIL ') + n + (c || !d ? '' : ': ' + d)); };

function mk(stored, state){
  const store = Object.assign({}, stored);
  const ls = {
    get length(){ return Object.keys(store).length; },
    key: (i) => Object.keys(store)[i],
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
  };
  const toasts = [];
  const scope = {
    localStorage: ls, SCN_KEY: 'scn', LS_KEY: 'st',
    S: state,
    currentScenarioName: () => (state && state.meta && state.meta.name) || 'Основной',
    snapshotState: () => JSON.parse(JSON.stringify(state)),
    toast: (m) => toasts.push(m),
    setSync: () => {},
    console: { error(){}, warn(){} },
    JSON, Object, String, Array, Math,
  };
  const api = new Function(...Object.keys(scope),
    'let _lsFull=false;' + code + '; return {loadScenarioList,saveScenarioList,_lsTrimScenarios,_lsUsage,_lsWarn};'
  )(...Object.values(scope));
  return { api, store, toasts };
}

const cur = { meta: { name: 'С майскими', _savedAt: '2026-09-06' }, big: 'x'.repeat(50) };
const other = {
  'Не трогать': { meta: { name: 'Не трогать' }, _savedAt: '2026-05-01', big: 'y'.repeat(50) },
  '26/27':      { meta: { name: '26/27' },      _savedAt: '2026-08-20', big: 'z'.repeat(50) },
};

console.log('--- 1. открытая версия не дублируется на диске ---');
let m = mk({ scn: JSON.stringify(other) }, cur);
let list = m.api.loadScenarioList();
t('в списке видны все три', Object.keys(list).length === 3, Object.keys(list).join(','));
t('открытая подставлена на лету', !!list['С майскими']);
m.api.saveScenarioList(list);
const written = JSON.parse(m.store.scn);
t('на диск записаны только ДВЕ', Object.keys(written).length === 2, Object.keys(written).join(','));
t('открытой на диске нет', !('С майскими' in written));
t('остальные целы', !!written['Не трогать'] && !!written['26/27']);

console.log('--- 2. запись не теряет данные при повторном чтении ---');
const again = m.api.loadScenarioList();
t('снова видны все три', Object.keys(again).length === 3);
t('открытая — свежий снимок состояния', again['С майскими'].meta.name === 'С майскими');

console.log('--- 3. под нож идёт самая давняя по выгрузке, а не первая в списке ---');
m = mk({ scn: JSON.stringify(other) }, cur);
t('удаление состоялось', m.api._lsTrimScenarios() === true);
const left = JSON.parse(m.store.scn);
t('осталась одна версия', Object.keys(left).length === 1, Object.keys(left).join(','));
t('осталась более свежая 26/27', !!left['26/27'], Object.keys(left).join(','));
t('про удаление сказано вслух', m.toasts.some(x => /Не трогать/.test(x)), JSON.stringify(m.toasts));
t('сказано, где версия осталась', m.toasts.some(x => /облаке|таблице/.test(x)));

console.log('--- 4. открытую версию не удаляем никогда ---');
m = mk({ scn: JSON.stringify({ 'С майскими': cur }) }, cur);
t('жертвовать нечем — открытая не в счёт', m.api._lsTrimScenarios() === false);

console.log('--- 5. предупреждение показывает занятый объём ---');
m = mk({ scn: JSON.stringify(other), st: 'q'.repeat(1000) }, cur);
m.api._lsWarn('тест');
t('в тексте есть мегабайты', m.toasts.some(x => /МБ/.test(x)), JSON.stringify(m.toasts));
t('сказано нажать «Сохранить»', m.toasts.some(x => /Сохранить/.test(x)));
const u = m.api._lsUsage();
t('объём посчитан', u.total > 2000, String(u.total));

console.log("--- 6. устаревшая копия открытой версии не должна побеждать живые данные ---");
// в SCN_KEY могла остаться копия от прежней версии приложения; при старте список сливается
// с таблицей по _savedAt, и такая копия откатила бы работу к последней выгрузке
const stale = Object.assign({}, other, { "С майскими": { meta:{name:"С майскими"}, _savedAt:"2026-09-06", big:"СТАРОЕ" } });
m = mk({ scn: JSON.stringify(stale) }, cur);
const merged = m.api.loadScenarioList();
t("открытая версия взята из живого состояния", merged["С майскими"].big !== "СТАРОЕ", merged["С майскими"].big);
t("а не из устаревшей записи", merged["С майскими"].big.length === 50);
m.api.saveScenarioList(merged);
t("и на диск устаревшая копия не вернулась", !("С майскими" in JSON.parse(m.store.scn)));

if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
console.log(NL + 'всё сошлось');
