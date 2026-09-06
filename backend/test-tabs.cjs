// Регистрация вкладки «Пробные» в навигации. Запуск: node backend/test-tabs.cjs
//
// Вкладка была добавлена в разметку, но НЕ вписана в TAB_DEFS/TAB_MODES. Механизм видимости
// перебирает именно TAB_DEFS, поэтому кнопку он не трогал вовсе, а при переходе в режим-дашборд
// (ruk/adm) не гасил её панель: строка вкладок пряталась, а содержимое «Пробных» оставалось на
// экране под кнопкой «Дашборд Руководителя».
const fs = require('fs');
const path = require('path');
const NL = String.fromCharCode(10);
const src = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

let bad = 0;
const t = (n, c, d) => { if (!c) bad++; console.log((c ? 'ok   ' : 'FAIL ') + n + (c || !d ? '' : ': ' + d)); };

function grabConst(name) {
  const i = src.indexOf('const ' + name + '=');
  if (i < 0) throw new Error('не найдено: ' + name);
  const j = src.indexOf(';', i);
  return src.slice(i, j + 1);
}
const api = new Function(
  grabConst('TAB_DEFS') + NL + grabConst('TAB_MODES') + NL + grabConst('TAB_BOTH_MODES') + NL
  + grabConst('SOLO_MODES') + NL + grabConst('NAV_MODES') + NL + grabConst('ADMIN_TABS') + NL
  + grabConst('OWNER_TABS') + NL + grabConst('ALWAYS_TABS') + NL
  + '; return {TAB_DEFS,TAB_MODES,TAB_BOTH_MODES,SOLO_MODES,NAV_MODES,ADMIN_TABS,OWNER_TABS};'
)();

t('вкладка есть в списке вкладок', api.TAB_DEFS.some(x => x[0] === 'trials'));
t('и подписана', (api.TAB_DEFS.find(x => x[0] === 'trials') || [])[1] === 'Пробные');
t('привязана к режиму', api.TAB_MODES.trials === 'work');
t('режим существует', api.NAV_MODES.indexOf(api.TAB_MODES.trials) >= 0);
t('видна и в «Финмодели»', !!api.TAB_BOTH_MODES.trials);
t('это админская вкладка, не владельческая', !!api.ADMIN_TABS.trials && !api.OWNER_TABS.trials);

// повторяем правило видимости из _updateTabVisibility
const visible = (mode) => (api.TAB_MODES.trials === mode || (api.TAB_BOTH_MODES.trials && !api.SOLO_MODES[mode]));
t('видна в «Работе»', visible('work'));
t('видна в «Финмодели»', visible('fin'));
t('скрыта в «Дашборде Руководителя»', !visible('ruk'));
t('скрыта в «Дашборде Администратора»', !visible('adm'));

// разметка на месте
t('кнопка есть в разметке', src.indexOf('data-t="trials"') >= 0);
t('панель есть в разметке', src.indexOf('class="panel" id="trials"') >= 0);

if (bad) { console.log(NL + 'провалено проверок: ' + bad); process.exit(1); }
console.log(NL + 'всё сошлось');
