<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_spoken_label_text(string $text): string {
    // Only convert labels that clearly introduce direct speech (opening quote).
    // This avoids changing ordinary labels such as "Ситуация:" or "Задача:".
    $pattern='/(^|\\R)(Оппонент|Клиент|Покупатель|Заказчик|Партн[её]р|Руководитель|Сотрудник|Команда\\s+[A-Za-zА-Яа-яЁё0-9_-]+):\\s*(?=[«“"])/u';
    return (string)preg_replace($pattern, '$1$2 говорит: ', $text);
}

function ckm_quiz_pro_host_number_phrase(int $n): string {
    $n=max(0,min(300,$n));
    $ones=[0=>'ноль',1=>'один',2=>'два',3=>'три',4=>'четыре',5=>'пять',6=>'шесть',7=>'семь',8=>'восемь',9=>'девять'];
    $teens=[10=>'десять',11=>'одиннадцать',12=>'двенадцать',13=>'тринадцать',14=>'четырнадцать',15=>'пятнадцать',16=>'шестнадцать',17=>'семнадцать',18=>'восемнадцать',19=>'девятнадцать'];
    $tens=[20=>'двадцать',30=>'тридцать',40=>'сорок',50=>'пятьдесят',60=>'шестьдесят',70=>'семьдесят',80=>'восемьдесят',90=>'девяносто'];
    $hundreds=[100=>'сто',200=>'двести',300=>'триста'];
    if(isset($ones[$n])) return $ones[$n];
    if(isset($teens[$n])) return $teens[$n];
    if(isset($tens[$n])) return $tens[$n];
    if(isset($hundreds[$n])) return $hundreds[$n];
    if($n<100){$base=intdiv($n,10)*10;return $tens[$base].' '.$ones[$n-$base];}
    $base=intdiv($n,100)*100; $rest=$n-$base;
    return $hundreds[$base].($rest>0?' '.ckm_quiz_pro_host_number_phrase($rest):'');
}

function ckm_quiz_pro_host_duration_phrase(int $seconds): string {
    $seconds=max(1,$seconds);
    if($seconds%60===0){
        $minutes=intdiv($seconds,60);
        $last=$minutes%10; $last2=$minutes%100;
        $word=($last===1 && $last2!==11)?'минута':(($last>=2 && $last<=4 && !($last2>=12 && $last2<=14))?'минуты':'минут');
        if($minutes===1) return 'одна минута';
        if($minutes===2) return 'две минуты';
        return ckm_quiz_pro_host_number_phrase($minutes).' '.$word;
    }
    if($seconds>60){
        $minutes=intdiv($seconds,60); $rest=$seconds%60;
        return ckm_quiz_pro_host_duration_phrase($minutes*60).' '.ckm_quiz_pro_host_duration_phrase($rest);
    }
    $last=$seconds%10; $last2=$seconds%100;
    $word=($last===1 && $last2!==11)?'секунда':(($last>=2 && $last<=4 && !($last2>=12 && $last2<=14))?'секунды':'секунд');
    return ckm_quiz_pro_host_number_phrase($seconds).' '.$word;
}

function ckm_quiz_pro_host_unique_answer_values(array $values): array {
    $out=[]; $seen=[];
    foreach($values as $value){
        if(!is_scalar($value)) continue;
        $display=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)$value)));
        if($display==='') continue;
        if(function_exists('mb_strtolower')) $key=mb_strtolower($display,'UTF-8');
        else {
            $key=strtolower($display);
            $key=strtr($key,[
                'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
            ]);
        }
        $key=trim($key," \t\n\r\0\x0B.,;:!?\"'«»()[]{}");
        if($key==='' || isset($seen[$key])) continue;
        $seen[$key]=true;
        $out[]=$display;
    }
    return $out;
}

function ckm_quiz_pro_host_remaining_phrase(int $seconds, string $subject='обсуждения'): string {
    $seconds=max(1,$seconds);
    $duration=ckm_quiz_pro_host_duration_phrase($seconds);
    $verb=(strpos($duration,'одна минута')===0 || strpos($duration,'секунда')!==false) ? 'осталась' : 'осталось';
    return 'До конца '.$subject.' '.$verb.' '.$duration.'.';
}


function ckm_quiz_pro_chgk_arbitration_voice_text(array $game, array $question): string {
    $gameId=(int)($game['id']??0); $questionId=(int)($question['id']??0);
    if($gameId<=0 || $questionId<=0) return 'Арбитраж завершён. Переходим к правильному ответу.';
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT answer_text,verdict,judge_comment,awarded_points FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND question_id=%d ORDER BY id ASC",
        $gameId,$questionId
    ),ARRAY_A) ?: [];
    if(!$rows) return 'Окончательный ответ команды не получен. Переходим к правильному ответу.';
    $parts=[];
    foreach($rows as $row){
        $answer=trim(preg_replace('/\\s+/u',' ',wp_strip_all_tags((string)($row['answer_text']??''))));
        $verdict=sanitize_key((string)($row['verdict']??'pending'));
        $comment=trim(preg_replace('/\\s+/u',' ',wp_strip_all_tags((string)($row['judge_comment']??''))));
        if($answer!=='') $piece='Ответ команды: '.$answer.'. ';
        else $piece='Ответ команды не получен. ';
        if(in_array($verdict,['accepted','correct'],true)) $piece.='Решение арбитра: ответ засчитан.';
        elseif(in_array($verdict,['rejected','incorrect'],true)) $piece.='Решение арбитра: ответ не засчитан.';
        else $piece.='Решение арбитра ещё не зафиксировано.';
        $generic=['Решение арбитра: ответ засчитан.','Решение арбитра: ответ не засчитан.'];
        if($comment!=='' && !in_array($comment,$generic,true)) $piece.=' '.$comment;
        $parts[]=$piece;
    }
    return implode(' ',array_values(array_unique($parts)));
}

if (!class_exists('CKM_Quiz_AI_Host')) {
    final class CKM_Quiz_AI_Host implements CKM_Quiz_Host_Adapter_Interface {
        public function compose(string $event, array $context): array {
            $q=(array)($context['question']??[]);
            $game=(array)($context['game']??[]);
            $format=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : sanitize_key((string)($game['format_key_snapshot']??''));
            $provider='standalone_local';

            $texts=[
                'game_created'=>'Игра создана. Подключитесь к своим командам.',
                'game_started'=>'Все команды подключились. Начинаем игру.',
                'question_started'=>!empty($q['question_text']) ? 'Следующий этап. '.trim(ckm_quiz_pro_spoken_label_text((string)$q['question_text'])) : 'Открыт следующий вопрос.',
                'question_closed'=>'Время вышло. Ответы зафиксированы.',
                'game_finished'=>'Игра завершена. Спасибо за участие.',
            ];

            if ($event==='game_started' && $format==='chgk') {
                $text='Команда готова. Начинаем «Битву знатоков». Победа — у стороны, которая первой набрала шесть баллов.';
            } elseif ($event==='question_started' && $format==='chgk') {
                $text='Внимание, вопрос. '.trim(ckm_quiz_pro_spoken_label_text((string)($q['question_text']??'')));
            } elseif ($event==='early_answer_offer' && $format==='chgk') {
                $settings=function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : [];
                $seconds=max(5,min(60,(int)($settings['earlyAnswerSeconds']??5)));
                $text='У команды есть '.ckm_quiz_pro_host_duration_phrase($seconds).', чтобы решить: дать досрочный ответ или перейти к обсуждению.';
            } elseif ($event==='discussion_started' && $format==='chgk') {
                $settings=function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : [];
                $seconds=max(60,min(300,(int)($settings['discussionSeconds']??($game['seconds_per_question_snapshot']??60))));
                $text='Начинается обсуждение. У вас '.ckm_quiz_pro_host_duration_phrase($seconds).'.';
            } elseif ($event==='discussion_warning' && $format==='chgk') {
                $remaining=max(1,(int)($context['remainingSeconds']??10));
                $text=ckm_quiz_pro_host_remaining_phrase($remaining,'обсуждения');
            } elseif ($event==='final_answer_opened' && $format==='chgk') {
                $seconds=20;
                $text='Обсуждение завершено. Зафиксируйте окончательный ответ команды. На это '.ckm_quiz_pro_host_duration_phrase($seconds).'.';
            } elseif ($event==='final_answer_warning' && $format==='chgk') {
                $remaining=max(1,(int)($context['remainingSeconds']??10));
                $text='Осталось '.ckm_quiz_pro_host_duration_phrase($remaining).', чтобы зафиксировать окончательный ответ.';
            } elseif ($event==='question_closed' && $format==='chgk') {
                $text=ckm_quiz_pro_chgk_arbitration_voice_text($game,$q);
            } elseif ($event==='answer_revealed' && $format==='chgk') {
                $decoded=function_exists('ckm_quiz_json_decode') ? ckm_quiz_json_decode((string)($q['correct_answers_json']??'')) : [];
                $correctValues=ckm_quiz_pro_host_unique_answer_values((array)$decoded);
                $correct=implode(' / ',$correctValues);
                $explanation=trim((string)($q['explanation']??''));
                $duel=function_exists('ckm_quiz_chgk_single_team_duel_state') ? ckm_quiz_chgk_single_team_duel_state($game) : ['enabled'=>false];
                $text='Правильный ответ'.($correct!==''?': '.$correct:'.');
                if($explanation!=='') $text.='. '.$explanation;
                if(!empty($duel['enabled'])){
                    $outcome=(string)($duel['currentOutcome']??'');
                    if($outcome==='experts') $text.=' Балл получает команда Знатоков.';
                    elseif($outcome==='game') $text.=' Балл получает Игра.';
                    $text.=' Счёт: Знатоки '.ckm_quiz_pro_host_number_phrase((int)($duel['expertsScore']??0)).', Игра '.ckm_quiz_pro_host_number_phrase((int)($duel['gameScore']??0)).'.';
                }
            } elseif ($event==='question_closed' && $format==='solution_price') {
                $review=(array)($context['solutionReview']??[]);
                $rows=array_values((array)($review['rows']??[]));
                if($rows){
                    $provider=(string)($review['provider']??'local_rubric');
                    $parts=[];
                    foreach($rows as $row){
                        $team=trim((string)($row['teamName']??''));
                        $score=(int)($row['score']??0);
                        $comment=trim((string)($row['comment']??''));
                        $prefix=$team!==''?$team.'. ':'';
                        $parts[]=$prefix.'Оценка: '.$score.' баллов. '.($comment!==''?$comment:'Разбор сохранён.');
                    }
                    $text='Разбор решения. '.implode(' ',$parts);
                } else {
                    $explanation=trim((string)($q['explanation']??''));
                    $text='Ответ зафиксирован. '.($explanation!==''?'Методический ориентир: '.$explanation:'Переходим к следующему этапу.');
                }
            } elseif ($format==='negotiation_duel' && str_starts_with($event,'negotiation_show_') && !empty($context['showHostText'])) {
                $text=trim((string)$context['showHostText']);
            } elseif ($event==='negotiation_show_finished' && $format==='negotiation_duel') {
                $scores=array_values((array)($context['showScores']??[]));
                $winners=array_values(array_filter(array_map('strval',(array)($context['winners']??[]))));
                $winningScore=max(0,(int)($context['winningScore']??0));
                usort($scores,static fn($a,$b)=>(int)($b['score']??0)<=>(int)($a['score']??0));
                $parts=[];
                foreach($scores as $row){
                    $name=trim((string)($row['name']??''));$score=max(0,(int)($row['score']??0));
                    if($name!=='')$parts[]=$name.' — '.$score.' очков';
                }
                $text='Игра «Переговори другого» завершена.';
                if($parts)$text.=' Итоговый счёт: '.implode('; ',$parts).'.';
                if(count($winners)>1)$text.=' Первое место разделили '.implode(' и ',$winners).'. Результат — '.$winningScore.' очков.';
                elseif(count($winners)===1)$text.=' Победитель — '.$winners[0].'. Результат — '.$winningScore.' очков.';
                $text.=' Спасибо за игру.';
            } elseif ($event==='question_closed' && $format==='negotiation_duel') {
                $review=(array)($context['negotiationReview']??[]);
                $rows=array_values((array)($review['rows']??[]));
                if($rows){
                    $provider=(string)($review['provider']??'local_rubric');
                    $parts=[];
                    foreach($rows as $row){
                        $team=trim((string)($row['teamName']??''));
                        $score=(int)($row['score']??0);
                        $comment=trim((string)($row['comment']??''));
                        $prefix=$team!==''?$team.'. ':'';
                        $parts[]=$prefix.'Оценка переговорного хода: '.$score.' баллов. '.($comment!==''?$comment:'Разбор сохранён.');
                    }
                    $text='Разбор переговорного раунда. '.implode(' ',$parts);
                } else {
                    $explanation=trim((string)($q['explanation']??''));
                    $text='Реплика зафиксирована. '.($explanation!==''?'Методический ориентир: '.$explanation:'Переходим к следующему раунду.');
                }
            } elseif ($event==='question_closed') {
                $answer=(array)($context['answer']??[]);
                $question=(array)($context['question']??[]);
                $correct=(string)($question['correct_answer']??$question['correct_answer_text']??'');
                $explanation=(string)($question['explanation']??'');
                $teamAnswer=(string)($answer['answer_text']??$answer['text']??'');
                $text='Разбор ответа.';
                if($correct!=='') $text.=' Правильный ответ: '.$correct.'.';
                if($teamAnswer!=='') $text.=' Ответ команды: '.$teamAnswer.'.';
                if($explanation!=='') $text.=' '.$explanation;
            } else {
                $text=$texts[$event]??'Игра продолжается.';
            }

            if ($event==='game_finished') {
                $duel=$format==='chgk' && function_exists('ckm_quiz_chgk_single_team_duel_state') ? ckm_quiz_chgk_single_team_duel_state($game) : ['enabled'=>false];
                if(!empty($duel['enabled'])){
                    $e=(int)($duel['expertsScore']??0); $gs=(int)($duel['gameScore']??0);
                    $text='Игра завершена. Итоговый счёт: Знатоки '.ckm_quiz_pro_host_number_phrase($e).', Игра '.ckm_quiz_pro_host_number_phrase($gs).'.';
                    if((string)($duel['winner']??'')==='experts') $text.=' Побеждают Знатоки.'; elseif((string)($duel['winner']??'')==='game') $text.=' Побеждает Игра.'; elseif($e>$gs) $text.=' Побеждают Знатоки.'; elseif($gs>$e) $text.=' Побеждает Игра.';
                } else {
                    $scores=(array)($context['scores']??[]);
                    $text='Игра завершена. Спасибо за участие.';
                    if(!empty($scores)){
                        arsort($scores);
                        $winner=key($scores);
                        $text.=' Лучший результат: '.$winner.'.';
                    }
                }
            }

            return ckm_quiz_host_output('ai',$event,$text,[
                'provider'=>$provider,
                'cloud'=>$provider==='ai_puffer',
                'gameId'=>(int)($game['id']??0),
                'format'=>$format,
            ]);
        }
    }
}
