// Блок «Пробные занятия» в отчёте для отдела продаж (рендер из дневного хранилища).
// Запуск: node backend/test-trials-ui.cjs
//
// Главное, что проверяется: «пробных не было» и «мы ещё не считали» — РАЗНЫЕ состояния.
// Дни, пересчитанные до появления этой статистики, поля trialDone не имеют, и выдать по ним
// ноль значило бы соврать.
const path = require('path');
const fs=require('fs');
const src=fs.readFileSync(path.join(__dirname,'..','index.html'),'utf8');
const NL=String.fromCharCode(10);
const g=n=>{ const i=src.indexOf('function '+n+'('); if(i<0) throw new Error('не найдена '+n);
  const j=src.indexOf(NL+'}', i); return src.slice(i, j+2)+NL; };
const esc=s=>String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
function run(store,S){
  const scope={esc,_realStore:store,S,_todayIso:()=>'2026-09-30',_salesRefresh(){},_trialMonth:'2026-09'};
  const code=g('_trialKidName')+g('_trialsHtml')+'; return _trialsHtml();';
  return new Function(...Object.keys(scope), code)(...Object.values(scope));
}
let bad=0;
const t=(n,c)=>{ if(!c) bad++; console.log((c?'ok   ':'FAIL ')+n); };
const day=(d,o)=>['2026-09-'+String(d).padStart(2,'0'),o];

console.log('--- 1. месяц ещё не пересчитывали ---');
const h1=run(Object.fromEntries([day(2,{lessons:12,present:1,all:1})]),{});
t('не выдаёт ноль за факт', h1.includes('ещё не считались'));
t('сказано, что нажать', h1.includes('Обновить месяц из Alfa'));
t('и что это не ноль', h1.includes('не ноль, а «не считали»'));

console.log('--- 2. посчитали, пробных не было ---');
const h2=run(Object.fromEntries([day(2,{lessons:12,trialDone:0,trialMissed:0})]),{});
t('честно говорит, что не нашлось', h2.includes('Пробных за этот месяц не нашлось'));
t('подсказывает про название абонемента', h2.includes('«пробн»'));

console.log('--- 3. рабочий случай ---');
const s3=Object.fromEntries([
  day(2,{lessons:12,trialDone:3,trialMissed:1,trialMissedIds:[501]}),
  day(3,{lessons:12,trialDone:1,trialMissed:2,trialMissedIds:[502,503]}),
  day(4,{lessons:11,trialDone:0,trialMissed:0}),
]);
const h3=run(s3,{children:{'501':{name:'Аня Петрова'},'502':{name:'Игорь Ким'}},grid:[]});
t('итог «должно было» = 7', h3.includes('>7</b>'));
t('дошли 4', h3.includes('>4</b>'));
t('не дошли 3', h3.includes('>3</b>'));
t('процент 57', h3.includes('57%'));
t('день без пробных строкой не выводится', !h3.includes('center">4</td>'));
t('имена недошедших показаны', h3.includes('Аня Петрова')&&h3.includes('Игорь Ким'));
t('неизвестный ребёнок показан как id', h3.includes('id 503'));
t('счётчик недошедших в заголовке', h3.includes('Кто не дошёл (3)'));

console.log('--- 4. Alfa не отдала id участника ---');
const h4=run(Object.fromEntries([day(2,{lessons:12,trialDone:1,trialMissed:0,trialNoCid:4})]),{});
t('предупреждение показано', h4.includes('не удалось определить ребёнка'));
t('сказано, что цифра занижена', h4.includes('цифра занижена'));

console.log('--- 5. повторное пробное у одного ребёнка ---');
const h5=run(Object.fromEntries([
  day(2,{lessons:12,trialDone:0,trialMissed:1,trialMissedIds:[501]}),
  day(9,{lessons:12,trialDone:0,trialMissed:1,trialMissedIds:[501]}),
]),{children:{'501':{name:'Аня Петрова'}},grid:[]});
t('показано, что не дошёл дважды', h5.includes('Аня Петрова ×2'));


if (bad) { console.log(String.fromCharCode(10) + "провалено проверок: " + bad); process.exit(1); }
console.log(String.fromCharCode(10) + "всё сошлось");
