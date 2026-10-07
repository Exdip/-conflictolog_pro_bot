<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class EvaluationService {
    public const VERSION='neg-eval-2.8';
    private EvaluationContextBuilder $contexts;private CriterionCalculator $calculator;private PhpEvaluator $php;private AiEvaluator $ai;private NoDealEvaluator $noDeal;private ResultBuilder $results;private ZopaService $zopa;
    public function __construct(?EvaluationContextBuilder $contexts=null,?CriterionCalculator $calculator=null,?PhpEvaluator $php=null,?AiEvaluator $ai=null,?NoDealEvaluator $noDeal=null,?ResultBuilder $results=null,?ZopaService $zopa=null){$this->contexts=$contexts?:new EvaluationContextBuilder();$this->calculator=$calculator?:new CriterionCalculator();$this->php=$php?:new PhpEvaluator($this->calculator);$this->ai=$ai?:new AiEvaluator();$this->noDeal=$noDeal?:new NoDealEvaluator();$this->results=$results?:new ResultBuilder();$this->zopa=$zopa?:new ZopaService();}
    private static function encode(mixed $v):string{return wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function decode(?string $json):array{if($json===null||$json==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return[];}}
    private function setSessionStatus(int $sessionId,string $status):void{global $wpdb;$t=Schema::table('sessions');$wpdb->update($t,['evaluation_status'=>$status,'updated_at'=>current_time('mysql',true)],['id'=>$sessionId]);}
    private function ensureEvaluation(int $sessionId):array{global $wpdb;$t=Schema::table('evaluations');$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE session_id=%d",$sessionId),ARRAY_A);if($row)return$row;$now=current_time('mysql',true);$ok=$wpdb->insert($t,['session_id'=>$sessionId,'evaluation_version'=>self::VERSION,'status'=>'processing','final_score'=>null,'result_type'=>'','red_line_breached'=>0,'summary_json'=>null,'created_at'=>$now,'completed_at'=>null]);if($ok===false){$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE session_id=%d",$sessionId),ARRAY_A);if($row)return$row;throw new \RuntimeException('Unable to create evaluation.');}return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE id=%d",(int)$wpdb->insert_id),ARRAY_A);}
    private function saveScore(int $evaluationId,int $ruleId,array $score):void{global $wpdb;$t=Schema::table('evaluation_scores');$data=['raw_score'=>$score['raw_score'],'weighted_score'=>$score['weighted_score'],'source'=>$score['source'],'confidence'=>$score['confidence'],'explanation'=>$score['explanation'],'evidence_json'=>self::encode($score['evidence'])];$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM `$t` WHERE evaluation_id=%d AND evaluation_rule_id=%d",$evaluationId,$ruleId));if($id){if($wpdb->update($t,$data,['id'=>(int)$id])===false)throw new \RuntimeException('Unable to update evaluation score.');}else{if($wpdb->insert($t,$data+['evaluation_id'=>$evaluationId,'evaluation_rule_id'=>$ruleId])===false)throw new \RuntimeException('Unable to save evaluation score.');}}
    private static function combine(float $php,float $share,float $ai):float{return max(0,min(100,$php*$share+$ai*(1-$share)));}
    private static function humanFallbackExplanation(string $code,float $score,array $php):string{
        $score=max(0.0,min(100.0,$score));
        return match($code){
            'result_quality'=>$score>=80
                ? 'Итоговое соглашение соответствует целевым условиям и не выходит за допустимые границы.'
                : ($score>=55 ? 'Итоговое соглашение остаётся допустимым, но часть целевых условий достигнута не полностью.' : 'Итоговое соглашение заметно отклоняется от целевых условий или приближается к критическим границам.'),
            'interest_discovery'=>$score>=80
                ? 'Ключевые интересы и риски оппонента выявлены и зафиксированы в ходе переговоров.'
                : ($score>=55 ? 'Часть важных интересов оппонента удалось выявить, но картина осталась неполной.' : 'Ключевые интересы и риски оппонента остались недостаточно выясненными.'),
            'concession_exchange'=>$score>=80
                ? 'Уступки использовались управляемо и сопровождались встречным движением.'
                : ($score>=55 ? 'Обмен уступками в целом контролировался, но не все уступки были связаны со встречными условиями.' : 'Уступки недостаточно связывались со встречными условиями и движением оппонента.'),
            'boundary_protection'=>$score>=80
                ? 'Допустимые границы были сохранены и не нарушены в итоговом результате.'
                : ($score>=55 ? 'Границы в целом сохранены, хотя в ходе переговоров возникал риск их пересечения.' : 'Защита допустимых границ была недостаточно устойчивой.'),
            'package_solution'=>$score>=80
                ? 'Условия обсуждались как связанный пакет, а не как набор изолированных уступок.'
                : ($score>=55 ? 'Пакетный подход использован частично; часть условий обсуждалась раздельно.' : 'Связанный пакет условий практически не использовался.'),
            default=>$score>=80
                ? 'По зафиксированным действиям критерий выполнен уверенно.'
                : ($score>=55 ? 'Критерий выполнен частично и требует более последовательного применения.' : 'По этому критерию требуется заметное улучшение.')
        };
    }
    private static function isSales(array $context):bool{return (string)($context['mechanics']['training_domain']??'')==='sales';}
    private function resultType(array $context,array $scores,array $noDeal,bool $breached,float $total):string{if(self::isSales($context)){$next=(float)($scores['next_step']['raw_score']??0);$signals=self::salesPlayerSignals($context);$nextOutcome=self::salesNextStepOutcome($context,$signals);$mode=(string)($context['mechanics']['success_mode']??'advance');if($mode==='fit_check'&&$total>=80&&!empty($signals['fit'])&&!empty($nextOutcome['confirmed']))return'sales_fit_correct';if($total>=80&&$next>=70&&!empty($nextOutcome['confirmed']))return'sales_advanced';if($total>=60)return'sales_interest';if($total>=40)return'sales_stalled';return'sales_value_not_proven';}if(($context['session']['status']??'')!=='completed_agreement')return (string)($noDeal['result_type']??'unclear_no_deal');$altRelation=(string)($context['zopa']['alternative_comparison']['final_agreement']['relation']??'');if($breached||$altRelation==='worse_than_alternative')return'agreement_weak';if($total>=80)return'agreement_strong';if($total>=55)return'agreement_acceptable';return'agreement_weak';}
    private static function summaryBuckets(array $scores):array{
        $rows=array_values($scores);
        usort($rows,fn($a,$b)=>(float)$b['raw_score']<=>(float)$a['raw_score']);
        $strengths=[];
        foreach($rows as $r){
            $score=(float)($r['raw_score']??0);
            if($score<80)continue;
            $strengths[]=['criterion_code'=>$r['code'],'text'=>$r['explanation'],'evidence_message_ids'=>$r['evidence_message_ids']];
            if(count($strengths)>=3)break;
        }
        $low=$rows;
        usort($low,fn($a,$b)=>(float)$a['raw_score']<=>(float)$b['raw_score']);
        $improvements=[];
        foreach($low as $r){
            $score=(float)($r['raw_score']??0);
            if($score>=80)continue;
            $prefix=$score<55?'Приоритетная зона развития — ':'Зона развития — ';
            $improvements[]=['criterion_code'=>$r['code'],'text'=>$prefix.$r['title'].'. '.$r['explanation'],'evidence_message_ids'=>$r['evidence_message_ids']];
            if(count($improvements)>=3)break;
        }
        return ['strengths'=>$strengths,'improvements'=>$improvements];
    }
    private static function salesFactFocus(array $context):string{
        $facts=(array)($context['facts']??[]);
        $missing=[];$available=[];
        foreach($facts as $fact){
            if(!is_array($fact))continue;
            $title=trim((string)($fact['title']??$fact['code']??''));
            if($title==='')continue;
            if(!in_array($title,$available,true))$available[]=$title;
            if((int)($fact['reveal_level']??0)<2&&!in_array($title,$missing,true))$missing[]=$title;
        }
        if($missing){
            $focus=array_slice($missing,0,3);
            return 'Доведите диагностику до конкретики по тем данным этой ситуации, которые остались не полностью раскрыты: '.implode(', ',$focus).'.';
        }
        if($available){
            $focus=array_slice($available,0,3);
            return 'Опирайтесь на конкретные данные, которые действительно есть в этой ситуации: '.implode(', ',$focus).'. Не запрашивайте показатели, которых в сценарии нет.';
        }
        return 'Опирайтесь только на конкретные данные, которые удалось подтвердить в этой ситуации, и не подменяйте отсутствующие показатели предположениями.';
    }

    private static function salesGrowthAdvice(array $row,array $context=[]):string{
        $code=(string)($row['code']??'');$title=trim((string)($row['title']??$code));
        $text=match($code){
            'customer_understanding'=>self::salesFactFocus($context),
            'question_quality'=>'Задавайте по одному точному вопросу за раз и проверяйте ответ уточнением, прежде чем переходить к аргументации.',
            'value_proposition'=>'Связывайте предложение с подтверждёнными цифрами клиента и показывайте расчёт эффекта, а не общую выгоду.',
            'objection_handling'=>'После аргумента проверяйте отдельным вопросом, снята ли конкретная причина сомнения клиента.',
            'next_step'=>'Фиксируйте следующий шаг конкретно: действие, срок или дату, участников и ожидаемый результат следующего контакта.',
            default=>'Сделайте этот навык более конкретным и проверяемым в следующей попытке.',
        };
        return 'Точка роста — '.$title.'. '.$text;
    }
    private function summary(array $scores,array $context,array $noDeal):array{
        $buckets=self::summaryBuckets($scores);
        if(self::isSales($context)){
            // Only real development zones belong here. Do not manufacture a fixed
            // number of weaknesses by pulling in criteria that already scored 80+.
            $improvements=(array)$buckets['improvements'];
            return ['strengths'=>$buckets['strengths'],'improvements'=>$improvements,'sales'=>['domain'=>'sales'],'coach_counts'=>(new MessageRepository())->coachCounts((int)$context['session']['id'])];
        }
        return ['strengths'=>$buckets['strengths'],'improvements'=>$buckets['improvements'],'relationship_dynamics'=>RelationshipService::postGameDynamics((array)($context['events']??[])),'zopa'=>(array)($context['zopa']['public']??[]),'no_deal'=>$noDeal,'coach_counts'=>(new MessageRepository())->coachCounts((int)$context['session']['id'])];
    }

    private function storedScores(int $evaluationId):array{
        global $wpdb;
        $scoreT=Schema::table('evaluation_scores');$rulesT=Schema::table('evaluation_rules');
        $rows=(array)$wpdb->get_results($wpdb->prepare(
            "SELECT s.raw_score,s.weighted_score,s.source,s.confidence,s.explanation,s.evidence_json,r.id AS rule_id,r.code,r.title,r.weight,r.sort_order
             FROM `$scoreT` s INNER JOIN `$rulesT` r ON r.id=s.evaluation_rule_id
             WHERE s.evaluation_id=%d ORDER BY r.sort_order ASC,r.id ASC",$evaluationId
        ),ARRAY_A);
        $out=[];
        foreach($rows as $row){
            $evidence=self::decode($row['evidence_json']??null);
            $code=(string)($row['code']??'');if($code==='')continue;
            $out[$code]=[
                'code'=>$code,'title'=>(string)($row['title']??$code),
                'raw_score'=>(float)($row['raw_score']??0),'weighted_score'=>(float)($row['weighted_score']??0),
                'source'=>(string)($row['source']??''),'confidence'=>(float)($row['confidence']??0),
                'explanation'=>(string)($row['explanation']??''),
                'evidence_message_ids'=>array_values(array_filter(array_map('intval',(array)($evidence['message_ids']??[])))),
                'weight'=>(float)($row['weight']??0),'sort_order'=>(int)($row['sort_order']??0),
            ];
        }
        return$out;
    }

    private static function fallbackEvidenceIds(array $context):array{
        $ids=[];
        foreach((array)($context['messages']??[]) as $message){
            if(($message['actor']??'')!=='player')continue;
            $id=(int)($message['id']??0);if($id>0)$ids[$id]=$id;
        }
        if(!$ids){
            foreach((array)($context['events']??[]) as $e){
                if(($e['actor']??'')!=='player')continue;
                $id=(int)($e['message_id']??0);if($id>0)$ids[$id]=$id;
            }
        }
        $values=array_values($ids);
        return count($values)<=3?$values:[$values[0],$values[(int)floor((count($values)-1)/2)],$values[count($values)-1]];
    }

    private static function salesLower(string $text):string{return function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);}
    private static function salesHasAny(string $text,array $cues):bool{foreach($cues as $cue){if(str_contains($text,$cue))return true;}return false;}
    private static function salesPlayerSignals(array $context):array{
        $out=['questions'=>[],'value'=>[],'next'=>[],'objection'=>[],'pressure'=>[],'discount'=>[],'fit'=>[]];
        $fitMode=(string)($context['mechanics']['success_mode']??'')==='fit_check';
        foreach((array)($context['messages']??[]) as $m){
            if(($m['actor']??'')!=='player')continue;
            $id=(int)($m['id']??0);$text=self::salesLower(trim((string)($m['content']??'')));if($id<=0||$text==='')continue;
            $question=str_contains($text,'?')||self::salesHasAny($text,['что ','почему','какой','какая','какие','сколько','кто ','как ','где ','когда','насколько','уточн']);
            if($question)$out['questions'][$id]=$id;
            if(self::salesHasAny($text,['дорог','цена','бюджет','сомнен','возраж','окуп','подумат','не время','поставщик','конкурент','риск','опасен','внедрен','скидк','не нужно','ничего не нужно','не принимаю решение','кп','коммерческ']))$out['objection'][$id]=$id;
            $value=self::salesHasAny($text,['окуп','расчёт','расчет','экономик','валов','прибыл','дополнительн','ценност','эффект','выгод','поможет','позволит','снизит','сократит','увеличит','ускорит','сэконом','решит','результат','избыточ','подходит','рекомендую'])
                && (preg_match('/\d/u',$text)===1||self::salesHasAny($text,['на ваших данных','на наших данных','для вас','у вас','ваш','сделк','задач','проблем']));
            // Do not treat a pure scheduling/acceptance question such as
            // «15 минут ... Подходит?» as proof of value. A question counts only
            // when it also contains an explicit outcome/value claim.
            $valueOutcome=self::salesHasAny($text,['окуп','экономик','валов','прибыл','дополнительн','ценност','эффект','выгод','поможет','позволит','снизит','сократит','увеличит','ускорит','сэконом','решит','результат','избыточ','рекомендую']);
            if(str_contains($text,'?')&&!$valueOutcome)$value=false;
            if($value)$out['value'][$id]=$id;
            $fit=$fitMode&&self::salesHasAny($text,['вам не нужен','вам это не нужно','не рекомендую','не стоит покупать','избыточ','более простой','проще решить','дешевле','минимально достаточ']);
            if($fit){$out['fit'][$id]=$id;$out['value'][$id]=$id;}
            $nextAction=self::salesHasAny($text,['встреч','созвон','назнач','подготовлю','подготовим','пришлю','отправлю','следующ','демонстрац','презентац','пилот','тест','коммерческ','кп','расчёт','расчет','план ','подключ','обсудим','разберём','разберем']);
            if(!$nextAction&&str_contains($text,'зафикс')&&self::salesHasAny($text,['встреч','созвон','дат','врем','следующ','услов']))$nextAction=true;
            $next=$nextAction&&(self::salesHasAny($text,['директор','руковод','с вами','вами','расчёт','расчет','данн','когда','дата','время','готовы','подходит','критер','срок','участник','завтра','недел'])||str_contains($text,'?')||preg_match('/\d/u',$text)===1);
            if($next)$out['next'][$id]=$id;
            if($fit)$out['next'][$id]=$id;
            if(self::salesHasAny($text,['давлю','обязаны','последний шанс','только сегодня','только сейчас','лучше не упускать','зафиксировать скидку','иначе','немедленно','прямо сейчас']))$out['pressure'][$id]=$id;
            if(str_contains($text,'скидк'))$out['discount'][$id]=$id;
        }
        foreach($out as $k=>$v)$out[$k]=array_values($v);
        return$out;
    }
    private static function salesNextStepAcceptance(string $text):bool{
        $text=self::salesLower($text);
        if(self::salesHasAny($text,['мне не подходит','нам не подходит','это не подходит','такой шаг не подходит','не готов','не готова','не готовы','не соглас','не будем','не хочу','не можем','пока рано','сначала нужно','сначала надо']))return false;

        if(self::salesHasAny($text,[
            'такой следующий шаг мне подходит','мне подходит',
            'готов обсудить','готова обсудить','готовы обсудить',
            'готов назначить','готова назначить','готовы назначить',
            'готов встрет','готова встрет','готовы встрет',
            'давайте назнач','можем назнач','давайте подготовим','давайте подготовлю',
            'подготовьте расчёт','подготовьте расчет','договорились','согласен','согласна',
            'спасибо за честность','разумно','подберите','тогда согласен','тогда согласна',
            'звучит отлично','звучит хорошо','звучит разумно'
        ]))return true;

        if(preg_match('/^(?:да|конечно|хорошо)[,!.]?\s+(?:это\s+)?(?:отличн|хорош|разумн)[^.!?]{0,35}(?:иде|вариант|шаг|встреч|формат)/u',$text)===1)return true;
        if(preg_match('/(?:такой|этот|предложенный)\s+(?:шаг|вариант|формат|подход)[^.!?]{0,30}(?:подходит|приемлем|разумн|хорош|отличн)/u',$text)===1)return true;

        // A client can confirm a concrete next step by allocating time to it.
        // This is especially natural in short diagnostic sales conversations:
        // «Я готова выделить 15 минут ...».
        if(preg_match('/готов(?:а|ы)?\s+выделить\s+(?:\d+\s*(?:минут\w*|час\w*)|время)/u',$text)===1)return true;

        $selfCommit=preg_match('/(?:^|[.!?]\s*)(?:отлично[!,.]?\s*)?(?:я|мы)\s+(?:подготовлю|подготовим|соберу|соберем|соберём|организую|организуем|назначу|назначим|пришлю|пришлем|пришлём|отправлю|отправим|согласую|согласуем|предложу|предложим|подключу|подключим|проведу|проведем|проведём)/u',$text)===1
            ||preg_match('/(?:я|мы)\s+[^.!?]{0,45}(?:подготовлю|подготовим|соберу|соберем|соберём|организую|организуем|назначу|назначим|пришлю|пришлем|пришлём|отправлю|отправим|согласую|согласуем|предложу|предложим|подключу|подключим|проведу|проведем|проведём)/u',$text)===1;
        $object=preg_match('/(?:встреч|созвон|расч[её]т|данн|пилот|демо|демонстрац|кп|коммерческ|презентац|директор|руковод|срок)/u',$text)===1;
        $timing=preg_match('/(?:когда|какой\s+день|какое\s+время|день\s+и\s+время|удобн|следующ(?:ей|ую|ая|ий)?\s+недел|завтра|послезавтра|в\s+(?:понедельник|вторник|среду|четверг|пятницу)|на\s+следующ)/u',$text)===1;
        return $selfCommit&&$object&&$timing;
    }

    private static function salesNextStepSpecificity(array $context,array $outcome):int{
        if(empty($outcome['confirmed']))return 0;
        $offerMap=array_fill_keys(array_map('intval',(array)($outcome['offer_ids']??[])),true);
        $best=0;
        foreach((array)($context['messages']??[]) as $message){
            if(($message['actor']??'')!=='player')continue;
            $id=(int)($message['id']??0);if($id<=0||!isset($offerMap[$id]))continue;
            $text=self::salesLower(trim((string)($message['content']??'')));
            if($text==='')continue;
            $timing=preg_match('/(?:сегодня|завтра|послезавтра|следующ(?:ей|ую|ая|ий)?\s+недел|в\s+(?:понедельник|вторник|среду|четверг|пятницу)|\b\d{1,2}[.:]\d{2}\b|\b\d{1,2}[\/. -]\d{1,2}(?:[\/. -]\d{2,4})?\b|дата|время|срок)/u',$text)===1;
            $participant=preg_match('/(?:директор|руковод|лпр|владелец|собственник|команд|финанс|закуп|ит|юрист|маркет|продаж|с\s+вами|мы\s+с\s+вами)/u',$text)===1;
            $deliverable=preg_match('/(?:расч[её]т|данн|кп|коммерческ|предложен|демо|демонстрац|пилот|презентац|аудит|план|смет|тз|материал)/u',$text)===1;
            $score=($timing?3:0)+($participant?3:0)+($deliverable?2:0);
            if($score>$best)$best=$score;
        }
        return min(8,$best);
    }

    private static function salesNextStepOutcome(array $context,?array $signals=null):array{
        $signals=$signals??self::salesPlayerSignals($context);
        $offerIds=array_values(array_filter(array_map('intval',array_values(array_unique(array_merge((array)($signals['next']??[]),(array)($signals['fit']??[])))))));
        $offerMap=array_fill_keys($offerIds,true);
        $confirmed=false;$rejected=false;$confirmationIds=[];
        $messages=array_values((array)($context['messages']??[]));
        foreach($messages as $i=>$message){
            if(($message['actor']??'')!=='player')continue;
            $id=(int)($message['id']??0);if($id<=0||!isset($offerMap[$id]))continue;
            for($j=$i+1;$j<count($messages);$j++){
                $reply=$messages[$j];$actor=(string)($reply['actor']??'');
                if($actor==='player')break;
                if($actor!=='opponent')continue;
                $text=self::salesLower(trim((string)($reply['content']??'')));
                if($text==='')break;
                $negative=self::salesHasAny($text,['мне не подходит','нам не подходит','это не подходит','такой шаг не подходит','не готов','не готова','не готовы','не соглас','не будем','не хочу','не можем','пока рано','сначала нужно','сначала надо']);
                $positive=self::salesNextStepAcceptance($text);
                if($negative){$rejected=true;break;}
                if($positive){$confirmed=true;$rid=(int)($reply['id']??0);if($rid>0)$confirmationIds[$rid]=$rid;break;}
                break;
            }
        }
        foreach((array)($context['commitments']??[]) as $commitment){
            if(!is_array($commitment))continue;
            if((string)($commitment['actor']??'')!=='opponent')continue;
            if(!in_array((string)($commitment['status']??'active'),['active','completed'],true))continue;
            $confirmed=true;
            $mid=(int)($commitment['created_message_id']??0);if($mid>0)$confirmationIds[$mid]=$mid;
        }
        return['offered'=>!empty($offerIds),'confirmed'=>$confirmed,'rejected'=>$rejected,'offer_ids'=>$offerIds,'confirmation_ids'=>array_values($confirmationIds)];
    }

    private static function salesEvidenceFor(string $code,array $signals,array $context):array{
        $ids=match($code){
            'customer_understanding','question_quality'=>$signals['questions'],
            'value_proposition'=>$signals['value'],
            'objection_handling'=>array_values(array_unique(array_merge($signals['objection'],$signals['questions'],$signals['value']))),
            'next_step'=>array_values(array_unique(array_merge($signals['next'],$signals['fit'],self::salesNextStepOutcome($context,$signals)['confirmation_ids']))),
            default=>[],
        };
        if(!$ids)$ids=self::fallbackEvidenceIds($context);
        return count($ids)<=3?$ids:[$ids[0],$ids[(int)floor((count($ids)-1)/2)],$ids[count($ids)-1]];
    }

    private static function agreementCoverage(array $context):float{
        $required=[];foreach((array)($context['items']??[]) as $item){
            if(!empty($item['required_for_agreement']))$required[(string)($item['code']??'')]=true;
        }
        if(!$required)return (($context['session']['status']??'')==='completed_agreement')?1.0:0.0;
        $have=[];foreach((array)($context['final_agreement']['package']??[]) as $row){
            if(is_array($row)&&isset($row['code']))$have[(string)$row['code']]=true;
        }
        $matched=0;foreach($required as $code=>$_){if(isset($have[$code]))$matched++;}
        return count($required)>0?$matched/count($required):0.0;
    }

    private static function genericAiFallback(array $rule,array $context):array{
        $code=(string)($rule['code']??'');
        $events=(array)($context['events']??[]);
        $count=function(array $types)use($events):int{
            $n=0;foreach($events as $e){
                if(($e['actor']??'')==='player'&&in_array((string)($e['event_type']??''),$types,true))$n++;
            }return$n;
        };
        $agreement=(($context['session']['status']??'')==='completed_agreement');
        $coverage=self::agreementCoverage($context);
        $red=!empty($context['completion']['red_line_breached']);
        $arguments=$count(['argument_made']);
        $probes=$count(['question_asked','interest_probe','constraint_probe','alternative_probe']);
        $packages=$count(['package_offer_made']);
        $positive=$count(['acknowledgement','deescalation','conditional_concession']);
        $hostile=$count(['personal_attack','ultimatum']);
        $backtrack=$count(['agreement_backtracking','unjustified_reopen_attempt']);
        $distinctItems=[];
        foreach($events as $e){
            if(($e['actor']??'')!=='player')continue;
            $p=is_array($e['payload']??null)?$e['payload']:[];
            $c=(string)($p['item_code']??'');if($c!=='')$distinctItems[$c]=true;
            foreach((array)($p['items']??[]) as $x){if((string)$x!=='')$distinctItems[(string)$x]=true;}
        }
        $multi=min(1.0,count($distinctItems)/4.0);

        $agreementCodes=[
            'goal_achievement','agreement_quality','agreement_specificity','agreement_feasibility',
            'responsibility_balance','accountability_balance','authority_accountability_match',
            'managerial_safety','result_focus','owner_fairness','governance_balance',
            'ownership_authority_alignment','operating_motivation','clean_separation',
            'gaming_prevention','blame_safety'
        ];
        $communicationCodes=[
            'communication','tension_management','defensiveness_management','depersonalization',
            'promise_integrity','perspective_listening'
        ];

        if(self::isSales($context)){
            $facts=(array)($context['facts']??[]);$revealed=0.0;
            foreach($facts as $fact){$level=(int)($fact['reveal_level']??0);if($level>=2)$revealed+=1.0;elseif($level===1)$revealed+=0.5;}
            $factRatio=count($facts)>0?$revealed/count($facts):0.0;
            $signals=self::salesPlayerSignals($context);
            $nextOutcome=self::salesNextStepOutcome($context,$signals);
            $q=count($signals['questions']);$v=count($signals['value']);$n=count($signals['next']);$fitN=count($signals['fit']);
            $pressureN=count($signals['pressure']);$discountN=count($signals['discount']);
            if($code==='customer_understanding'){$score=30+60*$factRatio+min(10,$q*2);$reason='Оценены реально раскрытые факты о клиенте и диагностические вопросы по всей беседе.';}
            elseif($code==='question_quality'){$score=35+min(45,$q*7.5)+20*$factRatio-$pressureN*10;$reason='Оценены содержательные вопросы по всей истории разговора, включая поздние уточнения.';}
            elseif($code==='value_proposition'){$score=34+($v>0?42:0)+18*$factRatio+min(6,max(0,$v-1)*6)-$pressureN*8;$reason=$v>0?'Аргументация или рекомендация связана с выявленными данными клиента и его задачей.':'Ценность недостаточно связана с конкретными данными клиента.';}
            elseif($code==='objection_handling'){
                $score=40+min(24,$q*4)+($v>0?24:0)+12*$factRatio-min(16,$discountN*8)-$pressureN*12;
                if($pressureN>0){$reason='В разговоре использовалось давление на клиента вместо последовательной диагностики причины возражения и доказательства ценности.';}
                elseif($discountN>0&&$v===0){$reason='Возражение в основном отрабатывалось скидкой без достаточной связи с экономикой и задачей клиента.';}
                else{$reason='Учтены диагностика причины сопротивления, связь с задачей клиента и отсутствие давления по всей беседе.';}
            }
            elseif($code==='next_step'){
                if(!empty($nextOutcome['confirmed'])){
                    $specificity=self::salesNextStepSpecificity($context,$nextOutcome);
                    $score=92+$specificity;
                    if($fitN>0)$reason='Продавец честно зафиксировал, что избыточная продажа не нужна, и клиент подтвердил это решение.';
                    elseif($specificity>=8)$reason='Следующий шаг зафиксирован образцово: есть конкретное действие, участники и срок, а клиент явно подтвердил продолжение.';
                    else $reason='Конкретный следующий шаг предложен продавцом и явно подтверждён клиентом.';
                }elseif(!empty($nextOutcome['offered'])){$score=58;$reason='Продавец предложил конкретный следующий шаг, но клиент его явно не подтвердил.';}
                else{$score=35;$reason='Конкретный следующий шаг не зафиксирован.';}
            }
            else{$score=45+30*$factRatio+min(15,$q*2)+($v>0?8:0)+($n>0?7:0)-$pressureN*10;$reason='ИИ-оценка временно недоступна; использована резервная оценка по полной истории продажи.';}
            $score=max(0.0,min(100.0,$score));
            return['raw_score'=>round($score,4),'confidence'=>0.65,'reason'=>$reason,'evidence_message_ids'=>self::salesEvidenceFor($code,$signals,$context),'level'=>$score<=20?'weak':($score<=40?'limited':($score<=60?'adequate':($score<=80?'strong':'excellent')))];
        }

        if(in_array($code,$agreementCodes,true)){
            $score=($agreement?55:30)+35*$coverage+($red?-25:5);
            $reason=$agreement
                ? 'Резервная серверная оценка учитывает полноту итогового пакета и отсутствие нарушения границ.'
                : 'Резервная серверная оценка снижена из-за отсутствия итогового соглашения.';
        }elseif($code==='argumentation'){
            $score=42+min(36,$arguments*12)+min(12,$probes*3)+($agreement?8:0)-$hostile*8;
            $reason='Резервная серверная оценка основана на зафиксированных аргументах, вопросах и результате переговоров.';
        }elseif($code==='process_management'){
            $score=40+min(20,$probes*4)+min(18,$packages*9)+18*$multi+($agreement?8:0)-$backtrack*10;
            $reason='Резервная серверная оценка основана на вопросах, пакетных предложениях, охвате предметов торга и результате.';
        }elseif($code==='multi_issue_use'){
            $score=35+45*$multi+min(20,$packages*10);
            $reason='Резервная серверная оценка учитывает число реально задействованных предметов торга и пакетные предложения.';
        }elseif($code==='constraint_work'){
            $score=38+min(35,$probes*7)+min(15,$packages*7.5)+($agreement?12:0);
            $reason='Резервная серверная оценка основана на проверке ограничений, вопросах и найденном рабочем пакете.';
        }elseif(in_array($code,$communicationCodes,true)){
            $score=72+min(12,$positive*4)-min(45,$hostile*18)-min(18,$backtrack*9);
            if($agreement)$score+=6;
            $reason='Резервная серверная оценка основана на валидированных событиях деловой коммуникации и эскалации.';
        }else{
            $score=50+($agreement?15:0)+20*$coverage+min(8,$arguments*4)+min(7,$probes*2)-$hostile*12-$backtrack*8;
            $reason='ИИ-оценка временно недоступна; использована резервная серверная оценка по валидированным событиям игры.';
        }
        $score=max(0.0,min(100.0,$score));
        return[
            'raw_score'=>round($score,4),
            'confidence'=>0.50,
            'reason'=>$reason,
            'evidence_message_ids'=>self::fallbackEvidenceIds($context),
            'level'=>$score<=20?'weak':($score<=40?'limited':($score<=60?'adequate':($score<=80?'strong':'excellent'))),
        ];
    }

    private function calculateAndSaveRule(int $evaluationId,array $rule,array $context,array $noDeal,?array $ai):array{
        $code=(string)$rule['code'];$type=$this->calculator->sourceType($rule);$share=$this->calculator->phpShare($rule);
        $php=['raw_score'=>0.0,'explanation'=>'','evidence'=>[]];
        if($type!=='ai')$php=$this->php->score($rule,$context,$noDeal);
        $fallback=null;
        if($type==='ai'&&!$ai)$fallback=self::genericAiFallback($rule,$context);
        $degraded=$type==='hybrid'&&!$ai;
        $raw=$type==='php'?(float)$php['raw_score']
            :($type==='ai'?($ai?(float)$ai['raw_score']:(float)$fallback['raw_score'])
            :($degraded?(float)$php['raw_score']:self::combine((float)$php['raw_score'],$share,(float)$ai['raw_score'])));
        $salesNextOutcome=null;
        if(self::isSales($context)&&$code==='next_step'){
            $salesNextOutcome=self::salesNextStepOutcome($context);
            if(empty($salesNextOutcome['confirmed']))$raw=min($raw,!empty($salesNextOutcome['offered'])?58.0:35.0);
        }
        $weighted=$raw*(float)$rule['weight']/100.0;
        $evidenceIds=[];
        foreach((array)($php['evidence']??[]) as $e){
            if(is_int($e)||ctype_digit((string)$e))$evidenceIds[(int)$e]=(int)$e;
            elseif(is_array($e)&&isset($e['message_id']))$evidenceIds[(int)$e['message_id']]=(int)$e['message_id'];
        }
        foreach((array)($ai['evidence_message_ids']??$fallback['evidence_message_ids']??[]) as $id)$evidenceIds[(int)$id]=(int)$id;
        if(self::isSales($context)&&$code==='next_step'&&is_array($salesNextOutcome)){
            foreach(array_merge((array)$salesNextOutcome['offer_ids'],(array)$salesNextOutcome['confirmation_ids']) as $id){$id=(int)$id;if($id>0)$evidenceIds[$id]=$id;}
        }
        $explanation=trim((string)($ai['reason']??$fallback['reason']??$php['explanation']??''));
        if(self::isSales($context)&&$code==='next_step'&&is_array($salesNextOutcome)&&empty($salesNextOutcome['confirmed'])){
            $explanation=!empty($salesNextOutcome['offered'])?'Продавец предложил конкретный следующий шаг, но клиент его явно не подтвердил.':'Конкретный следующий шаг не зафиксирован.';
        }
        $confidence=$type==='php'?1.0:($type==='ai'&&!$ai?0.50:($degraded?0.65:(float)$ai['confidence']));
        $source=$type==='ai'&&!$ai?'server_fallback':($degraded?'php_fallback':$type);
        if($degraded)$explanation=self::humanFallbackExplanation($code,$raw,$php);
        $public=[
            'code'=>$code,'title'=>(string)$rule['title'],'raw_score'=>round($raw,4),
            'weighted_score'=>round($weighted,4),'source'=>$source,'confidence'=>$confidence,
            'explanation'=>$explanation,'evidence_message_ids'=>array_values(array_filter($evidenceIds)),
            'weight'=>(float)$rule['weight'],'sort_order'=>(int)($rule['sort_order']??0),
        ];
        $this->saveScore($evaluationId,(int)$rule['id'],[
            'raw_score'=>$public['raw_score'],'weighted_score'=>$public['weighted_score'],
            'source'=>$source,'confidence'=>$confidence,'explanation'=>$explanation,
            'evidence'=>['message_ids'=>$public['evidence_message_ids'],'php'=>$php['evidence']??[],'ai_level'=>$ai['level']??null],
        ]);
        return$public;
    }

    public function evaluate(int $sessionId,bool $forceRetry=false):array{
        global $wpdb;
        $lockRepo=new SessionRepository();
        $lockToken='eval-'.(function_exists('wp_generate_uuid4')?wp_generate_uuid4():bin2hex(random_bytes(16)));
        if(!$lockRepo->acquireProcessingLock($sessionId,$lockToken,90))return$this->results->build($sessionId);
        try{
            $session=Access::session($sessionId);
            if(!str_starts_with((string)$session['status'],'completed_'))throw new \RuntimeException('Evaluation is available only after completion.');
            $existing=$this->results->build($sessionId);
            if(($existing['status']??'')==='completed'&&($existing['evaluation_version']??'')===self::VERSION)return$existing;

            $context=$this->contexts->build($sessionId);$context['zopa']=self::isSales($context)?['public'=>[]]:$this->zopa->analyze($context);
            $rules=$context['evaluation_rules'];
            $weight=array_sum(array_map(fn($r)=>(float)$r['weight'],$rules));
            if(abs($weight-100.0)>0.0001)throw new \RuntimeException('Evaluation weights must total 100.');

            $evaluation=$this->ensureEvaluation($sessionId);$evalId=(int)$evaluation['id'];
            $evalT=Schema::table('evaluations');$scoreT=Schema::table('evaluation_scores');
            $sameVersion=(string)($evaluation['evaluation_version']??'')===self::VERSION;
            $meta=$sameVersion?self::decode($evaluation['summary_json']??null):[];
            $aiDegraded=!empty($meta['ai_degraded']);

            if(!$sameVersion){
                $wpdb->delete($scoreT,['evaluation_id'=>$evalId]);
                $aiDegraded=false;
            }
            $this->setSessionStatus($sessionId,'processing');
            $wpdb->update($evalT,[
                'evaluation_version'=>self::VERSION,'status'=>'processing','final_score'=>null,
                'result_type'=>'','summary_json'=>null,'completed_at'=>null
            ],['id'=>$evalId]);

            try{
                $noDeal=self::isSales($context)?['result_type'=>'sales','reason'=>'sales_completion']:$this->noDeal->classify($context);
                $saved=$this->storedScores($evalId);

                foreach($rules as $rule){
                    $code=(string)$rule['code'];
                    if(isset($saved[$code]))continue;
                    if($this->calculator->sourceType($rule)==='php'){
                        $this->calculateAndSaveRule($evalId,$rule,$context,$noDeal,null);
                    }
                }
                $saved=$this->storedScores($evalId);

                $nextAi=null;
                foreach($rules as $rule){
                    $code=(string)$rule['code'];
                    if(isset($saved[$code]))continue;
                    if($this->calculator->sourceType($rule)!=='php'){$nextAi=$rule;break;}
                }
                if($nextAi){
                    $code=(string)$nextAi['code'];
                    $aiScore=null;
                    if(!$aiDegraded){
                        $aiScores=$this->ai->evaluate([$nextAi],$context);
                        $aiScore=$aiScores[$code]??null;
                        if(!$aiScore)$aiDegraded=true;
                    }
                    $this->calculateAndSaveRule($evalId,$nextAi,$context,$noDeal,$aiScore);
                    $saved=$this->storedScores($evalId);
                }

                if(count($saved)<count($rules)){
                    $wpdb->update($evalT,[
                        'status'=>'processing',
                        'summary_json'=>self::encode(['progress'=>['completed'=>count($saved),'total'=>count($rules)],'ai_degraded'=>$aiDegraded])
                    ],['id'=>$evalId]);
                    $this->setSessionStatus($sessionId,'processing');
                    return$this->results->build($sessionId);
                }

                $total=0.0;foreach($saved as $score)$total+=(float)$score['weighted_score'];
                $redBreached=!empty($context['completion']['red_line_breached']);
                $resultType=$this->resultType($context,$saved,$noDeal,$redBreached,$total);
                $summary=$this->summary($saved,$context,$noDeal);$now=current_time('mysql',true);
                $wpdb->update($evalT,[
                    'status'=>'completed','final_score'=>round($total,4),'result_type'=>$resultType,
                    'red_line_breached'=>$redBreached?1:0,'summary_json'=>self::encode($summary),'completed_at'=>$now
                ],['id'=>$evalId]);
                $this->setSessionStatus($sessionId,'completed');
                return$this->results->build($sessionId);
            }catch(\Throwable $e){
                $stage=$e instanceof EvaluationUnavailableException?'ai':($e instanceof \UnexpectedValueException?'validate':'runtime');
                $wpdb->update($evalT,[
                    'status'=>'failed',
                    'summary_json'=>self::encode(['error_code'=>'evaluation_failed','failure_stage'=>$stage])
                ],['id'=>$evalId]);
                $this->setSessionStatus($sessionId,'failed');
                throw$e;
            }
        }finally{$lockRepo->releaseProcessingLock($sessionId,$lockToken);}
    }

    public function result(int $sessionId):array{return $this->results->build($sessionId);}
}
