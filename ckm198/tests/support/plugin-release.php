<?php
/** Current release contract, shared by historical feature regressions. */
function ckm_test_current_plugin_release(string $source): bool {
    if(!preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m',$source,$header))return false;
    if(!preg_match('/define\(\s*[\'\"]CKM_QUIZ_PRO_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',$source,$constant))return false;
    return $header[1]===$constant[1]
        && (bool)preg_match('/^0\.3\.23\.\d+-dev\.\d+-[A-Z0-9-]+$/D',$header[1]);
}

/** Later schema/content/evaluator versions retain the historical feature tests. */
function ckm_test_declared_version_at_least(string $source,string $constant,string $minimum): bool {
    $name=preg_quote($constant,'/');
    preg_match_all('/\bconst\s+'.$name.'\s*=\s*(?:[\'\"]([^\'\"]+)[\'\"]|(\d+))\s*;/',$source,$matches,PREG_SET_ORDER);
    if(!$matches){
        preg_match_all('/define\(\s*[\'\"]'.$name.'[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',$source,$matches,PREG_SET_ORDER);
    }
    if(count($matches)!==1)return false;
    $actual=$matches[0][1]!==''?$matches[0][1]:($matches[0][2]??'');
    if(!preg_match('/^(.*?)(\d+(?:\.\d+)*)([^\d.]*)$/D',$actual,$a)
        ||!preg_match('/^(.*?)(\d+(?:\.\d+)*)([^\d.]*)$/D',$minimum,$b))return false;
    return $a[1]===$b[1] && $a[3]===$b[3] && version_compare($a[2],$b[2],'>=');
}
