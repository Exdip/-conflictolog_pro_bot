<?php
if(PHP_SAPI!=='cli')exit;
$n=0;function ok178($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n).' '.$label."\n";}
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
ok178(str_contains($js,"sttTargetInput=null"),'shared STT has dynamic target');
ok178(str_contains($js,'function syncShowSttControls(show,disabled,idleLabel)'),'negotiation show STT synchronizer exists');
ok178(str_contains($js,"$('showMessageText')"),'negotiation textarea is a dictation target');
ok178(str_contains($js,"'🎙 Продиктовать реплику'"),'free dialogue dictation label exists');
ok178(str_contains($js,"'🎙 Продиктовать рассказ'"),'story dictation label exists');
ok178(str_contains($js,"'🎙 Продиктовать вопрос'"),'question dictation label exists');
ok178(str_contains($js,"'🎙 Продиктовать ответ'"),'answer dictation label exists');
ok178(str_contains($js,"const text=sttTargetInput||$('answerText')"),'recognizer writes to current text target');
ok178(str_contains($js,"text.value=prev?(prev+' '+v):v"),'repeat dictation appends instead of replacing');
ok178(str_contains($js,'Одна диктовка — до 30 секунд. Завершаем фрагмент…'),'30 second STT chunk is explained as fragment');
ok178(str_contains($js,'Голосовой ввод готов · аудио не сохраняется'),'participant sees no-audio-storage note');
ok178(str_contains($js,"syncShowSttControls(MODE==='play'&&!!s.canMessage"),'dictation follows negotiation message availability');
ok178(str_contains($js,'function startParticipantMediaRecorder(stream,configure)'),'participant recorder fallback exists');
ok178(str_contains($js,"const candidates=['','audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus']"),'browser-default recorder is tried before explicit MIME fallbacks');
ok178(str_contains($js,'Микрофон разрешён, но браузер не получил активную аудиодорожку.'),'dead audio track gets a precise diagnostic');
ok178(str_contains($js,"'Не удалось начать запись микрофона: '+sttRecorderErrorText(e)"),'MediaRecorder startup exposes concrete error');
echo "ALL $n PASS\n";
