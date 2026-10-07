<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }
if (!class_exists(ArbiterResponseParser::class)) { require_once __DIR__ . '/../ai/arbiter/arbiter-response-parser.php'; }

final class EvaluationUnavailableException extends \RuntimeException {}

final class AiEvaluator {
    private $transport;
    public function __construct(?callable $transport=null){$this->transport=$transport;}
    private static function json(mixed $v):string{return wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private function request(array $messages,int $timeout=20,int $maxTokens=1200):string{
        if($this->transport){$r=($this->transport)($messages,$timeout);if(!is_string($r))throw new EvaluationUnavailableException('ИИ-оценка недоступна.');return $r;}
        if(!function_exists('ckm_quiz_pro_aipuffer_post'))throw new EvaluationUnavailableException('ИИ-подключение недоступно.');
        $settings=function_exists('ckm_quiz_pro_solution_price_ai_settings')?ckm_quiz_pro_solution_price_ai_settings():['provider'=>'openai','model'=>'gpt-4o-mini'];
        $maxTokens=max(700,min(2200,$maxTokens));
        $body=['provider'=>(string)($settings['provider']??'openai'),'model'=>(string)($settings['model']??'gpt-4o-mini'),'messages'=>$messages,'ai_params'=>['temperature'=>0.10,'max_completion_tokens'=>$maxTokens],'stream'=>false];
        $response=ckm_quiz_pro_aipuffer_post($body,$timeout);if(is_wp_error($response))throw new EvaluationUnavailableException('ИИ-оценка недоступна.');
        $code=function_exists('wp_remote_retrieve_response_code')?(int)wp_remote_retrieve_response_code($response):0;if($code<200||$code>=300)throw new EvaluationUnavailableException('ИИ-оценка недоступна.');
        $raw=function_exists('wp_remote_retrieve_body')?(string)wp_remote_retrieve_body($response):'';$json=json_decode($raw,true);
        $content='';
        if(is_array($json)){
            $candidate=$json['content']??$json['reply']??'';
            if(is_string($candidate))$content=$candidate;
            elseif(is_array($candidate))$content=self::json($candidate);
        }
        if($content==='')throw new EvaluationUnavailableException('ИИ-оценка недоступна.');return $content;
    }
    private static function extractJson(string $raw):array{
        try{return (new ArbiterResponseParser())->parse($raw);}catch(\Throwable){throw new EvaluationUnavailableException('ИИ-оценка вернула неразбираемый ответ.');}
    }
    private static function rubricScale(array $rule):?array{
        $scale=(array)($rule['rubric']['scale']??[]);$min=$scale['min']??null;$max=$scale['max']??null;
        if(!is_numeric($min)||!is_numeric($max))return null;$min=(float)$min;$max=(float)$max;if($max<=$min)return null;return[$min,$max];
    }
    private static function normalizeRawScore(float $raw,array $rule):float{
        $scale=self::rubricScale($rule);
        if($scale){[$min,$max]=$scale;
            // Custom small scales (0-5, 0-10 etc.) are author-facing. Convert them to internal 0-100.
            if($max<=20.0&&$raw>=$min&&$raw<=$max&&abs($max-$min)>0.000001){$raw=($raw-$min)/($max-$min)*100.0;}
        }
        if($raw<0||$raw>100)throw new \UnexpectedValueException('Evaluator score is outside 0-100.');
        return $raw;
    }
    private static function normalizeConfidence(mixed $value):float{
        if($value===null||$value==='')return 0.5;if(!is_numeric($value))return 0.5;$confidence=(float)$value;if($confidence>1.0&&$confidence<=100.0)$confidence/=100.0;return max(0.0,min(1.0,$confidence));
    }
    private static function levelForScore(float $raw):string{
        if($raw<=20)return'weak';if($raw<=40)return'limited';if($raw<=60)return'adequate';if($raw<=80)return'strong';return'excellent';
    }
    private static function validateOne(array $row,array $rule,bool $single=false):array{
        $expected=(string)($rule['code']??'');$code=trim((string)($row['criterion_code']??$row['code']??''));
        if($code===''&&$single)$code=$expected;
        if($code!==$expected)throw new \UnexpectedValueException('Evaluator criterion mismatch.');
        if(!is_numeric($row['raw_score']??null))throw new \UnexpectedValueException('Evaluator score is missing.');
        $raw=self::normalizeRawScore((float)$row['raw_score'],$rule);$confidence=self::normalizeConfidence($row['confidence']??null);
        if($confidence<0||$confidence>1)throw new \UnexpectedValueException('Evaluator confidence is invalid.');
        // The server derives the canonical level from the normalized numeric score. A model label cannot invalidate a valid score.
        $level=self::levelForScore($raw);
        $evidence=[];foreach((array)($row['evidence_message_ids']??$row['evidence']??[]) as $id){if(is_array($id))$id=$id['message_id']??0;$id=(int)$id;if($id>0)$evidence[$id]=$id;}
        $reason=trim(strip_tags((string)($row['reason']??$row['explanation']??'')));if(function_exists('mb_substr'))$reason=mb_substr($reason,0,1200,'UTF-8');else$reason=substr($reason,0,1200);
        return ['criterion_code'=>$code,'level'=>$level,'raw_score'=>round($raw,4),'confidence'=>round($confidence,5),'evidence_message_ids'=>array_values($evidence),'reason'=>$reason];
    }
    private static function rowsFromParsed(array $parsed):array{
        $rows=$parsed['evaluations']??$parsed['results']??$parsed['evaluation']??null;
        if(is_string($rows)){try{$nested=(new ArbiterResponseParser())->parse($rows);$rows=$nested['evaluations']??$nested['results']??$nested['evaluation']??$nested;}catch(\Throwable){$rows=[];}}
        if($rows===null&&array_is_list($parsed))$rows=$parsed;
        if($rows===null&&(isset($parsed['raw_score'])||isset($parsed['criterion_code'])))$rows=[$parsed];
        if(is_array($rows)&&!array_is_list($rows)&&(isset($rows['raw_score'])||isset($rows['criterion_code'])))$rows=[$rows];
        return is_array($rows)?$rows:[];
    }
    private function prompt(array $criteria,array $context,bool $strict=false):array{
        $criterionPayload=[];foreach($criteria as $r){$criterionPayload[]=['code'=>$r['code'],'title'=>$r['title'],'weight'=>(float)$r['weight'],'rubric'=>$r['rubric']??[]];}
        $sourceMessages=(array)$context['messages'];if(count($sourceMessages)>24)$sourceMessages=array_slice($sourceMessages,-24);$transcript=array_map(fn($m)=>['id'=>(int)$m['id'],'actor'=>$m['actor'],'text'=>$m['content']],$sourceMessages);
        $facts=array_map(fn($f)=>['code'=>$f['code'],'title'=>$f['title'],'full_content'=>$f['content'],'reveal_level'=>(int)$f['reveal_level'],'first_discovered_message_id'=>$f['first_discovered_message_id']],(array)$context['facts']);
        $events=array_map(fn($e)=>['message_id'=>(int)$e['message_id'],'event_type'=>$e['event_type'],'actor'=>$e['actor'],'payload'=>$e['payload']],(array)$context['events']);
        $isSales=(string)($context['mechanics']['training_domain']??'')==='sales';
        $system=$isSales
            ? "Ты пост-игровой ИИ-оценщик тренажёра продаж. Оценивай только перечисленные критерии, каждый независимо. Не рассчитывай общий балл /100. Используй только evidence из диалога и структурированных событий. Полная скрытая карточка доступна только ретроспективно: оценивай, что продавец действительно выяснил, как связал предложение с задачей клиента, как работал с сомнением и зафиксировал ли конкретный следующий шаг. Не приписывай продавцу знания или действия, которых нет в evidence. Не считай абстрактное «пришлю информацию» сильным следующим шагом без достаточной конкретики. Неподтверждённые цифры и обещания не считай доказанной ценностью. Если mechanics.success_mode=fit_check, честный обоснованный вывод, что исходно запрошенная покупка клиенту не нужна, является сильным профессиональным результатом, а не провалом продажи.\nПоле raw_score ВСЕГДА возвращай по внутренней шкале 0-100. confidence — от 0 до 1. reason — одно короткое предложение до 240 символов. evidence_message_ids — максимум 3 наиболее существенных сообщения. Верни только JSON: {\"evaluations\":[{\"criterion_code\":\"...\",\"level\":\"weak|limited|adequate|strong|excellent\",\"raw_score\":0,\"confidence\":0.0,\"evidence_message_ids\":[1],\"reason\":\"...\"}]}"
            : "Ты пост-игровой ИИ-оценщик переговорного тренажёра. Оценивай только перечисленные критерии, каждый независимо. Не рассчитывай общий балл /100 и не объявляй победителя. Используй только evidence из диалога и структурированных событий. Полная скрытая карточка доступна только для ретроспективного объяснения того, что участник обнаружил или пропустил. Не приписывай участнику действия, которых нет в evidence.\nПоле raw_score ВСЕГДА возвращай по внутренней шкале 0-100, даже если пользовательская рубрика описана в шкале 0-5 или 0-10. confidence возвращай от 0 до 1. reason — одно короткое предложение до 240 символов. evidence_message_ids — максимум 3 наиболее существенных сообщения. Верни только JSON: {\"evaluations\":[{\"criterion_code\":\"...\",\"level\":\"weak|limited|adequate|strong|excellent\",\"raw_score\":0,\"confidence\":0.0,\"evidence_message_ids\":[1],\"reason\":\"...\"}]}";
        if($strict)$system.="\nЭто повторная попытка. Строго соблюдай JSON. Не округляй шкалу критерия до пользовательского диапазона: raw_score должен быть именно 0-100. Указывай только реальные message_id.";
        $payload=['criteria'=>$criterionPayload,'mechanics'=>$context['mechanics']??[],'player_card'=>$context['player_card'],'opponent_card'=>$context['opponent_card'],'facts'=>$facts,'transcript'=>$transcript,'events'=>$events,'commitments'=>$context['commitments']??[],'final_agreement'=>$context['final_agreement'],'completion'=>$context['completion']];
        return [['role'=>'system','content'=>$system],['role'=>'user','content'=>self::json($payload)]];
    }
    private function evaluateSubset(array $rules,array $context,bool $single=false,bool $strict=false):array{
        if(!$rules)return[];
        $ruleByCode=[];foreach($rules as $rule){$code=trim((string)($rule['code']??''));if($code!=='')$ruleByCode[$code]=$rule;}
        if(!$ruleByCode)return[];
        $count=count($ruleByCode);
        // Keep each model response compact enough to finish reliably in the live request budget.
        $maxTokens=max(700,min(1350,420+$count*300));
        try{$parsed=self::extractJson($this->request($this->prompt(array_values($ruleByCode),$context,$strict),20,$maxTokens));}
        catch(EvaluationUnavailableException){return[];}
        $rows=self::rowsFromParsed($parsed);$found=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $code=trim((string)($row['criterion_code']??$row['code']??''));
            if($single&&count($ruleByCode)===1&&$code==='')$code=(string)array_key_first($ruleByCode);
            if(!isset($ruleByCode[$code]))continue;
            try{$found[$code]=self::validateOne($row,$ruleByCode[$code],$single&&count($ruleByCode)===1);}catch(\Throwable){}
        }
        return$found;
    }
    public function evaluate(array $rules,array $context):array{
        if(!$rules)return[];
        $valid=[];$byCode=[];
        foreach($rules as $rule){$code=trim((string)($rule['code']??''));if($code==='')continue;$valid[]=$rule;$byCode[$code]=$rule;}
        if(!$valid)return[];

        // A resumable evaluation service calls this with one criterion at a time.
        // Do not spend a second identical AI request immediately when that single call fails;
        // the service can fall back deterministically and preserve progress.
        if(count($valid)===1){
            return $this->evaluateSubset($valid,$context,true,false);
        }

        $result=[];
        foreach(array_chunk($valid,3) as $chunk){
            $part=$this->evaluateSubset($chunk,$context,count($chunk)===1,false);
            foreach($part as $code=>$row)$result[$code]=$row;
        }
        $missing=[];foreach($valid as $rule){$code=(string)$rule['code'];if(!isset($result[$code]))$missing[]=$rule;}
        if($missing){
            $repair=array_slice($missing,0,3);
            $part=$this->evaluateSubset($repair,$context,count($repair)===1,true);
            foreach($part as $code=>$row)$result[$code]=$row;
        }
        return$result;
    }
}
