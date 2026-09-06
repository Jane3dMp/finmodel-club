<?php
declare(strict_types=1);
$bad=0;
function ok(string $n,$g,$w):void{global $bad;$c=$g===$w;if(!$c)$bad++;
 echo ($c?"✓ ":"✗ ").$n.' = '.json_encode($g,JSON_UNESCAPED_UNICODE).($c?'':' ≠ '.json_encode($w,JSON_UNESCAPED_UNICODE))."\n";}
function cfg():array{ return []; }
$lib=file_get_contents(__DIR__.'/../api/alfa/lib.php');
foreach(['alfa_trial_prices','alfa_is_trial_sum','alfa_trial_state'] as $fn){
  if(!preg_match('/\nfunction '.$fn.'\(.*?\n\}/s',$lib,$m)){echo "не найдено: $fn\n";exit(1);}
  eval($m[0]);
}
echo "--- какие суммы считаем пробными ---\n";
ok('по умолчанию 0 и 15', alfa_trial_prices(), [0.0,15.0]);
ok('15 — пробный', alfa_is_trial_sum(15.0), true);
ok('0 — пробный (шаблон не проставлен)', alfa_is_trial_sum(0.0), true);
ok('36 — обычный ребёнок', alfa_is_trial_sum(36.0), false);
ok('28 — со скидкой, не пробный', alfa_is_trial_sum(28.0), false);
ok('46 — не пробный', alfa_is_trial_sum(46.0), false);
ok('15.00 строкой из Alfa', alfa_is_trial_sum((float)"15.00"), true);

echo "\n--- три состояния вечером ---\n";
ok('занятие не проведено — ждём', alfa_trial_state(15.0,null,false), 'waiting');
ok('списали 15 — пришёл', alfa_trial_state(15.0,true,true), 'came');
ok('списали, но отметки нет — всё равно пришёл (деньги главнее)', alfa_trial_state(15.0,null,true), 'came');
ok('0 и отметка есть — ПРИШЁЛ, абонемент не проставили', alfa_trial_state(0.0,true,true), 'came_free');
ok('0 и отметки нет — не пришёл', alfa_trial_state(0.0,false,true), 'missed');
ok('0 и отметка null — не пришёл', alfa_trial_state(0.0,null,true), 'missed');
ok('отметка единицей', alfa_trial_state(0.0,1,true), 'came_free');
ok('отметка строкой', alfa_trial_state(0.0,'1',true), 'came_free');
echo $bad?"\n❌ провалено: $bad\n":"\n✅ всё сошлось\n";
exit($bad?1:0);
