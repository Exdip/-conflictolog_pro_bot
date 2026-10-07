<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }
final class PhpEvaluator {
    private CriterionCalculator $calculator;
    public function __construct(?CriterionCalculator $calculator=null){$this->calculator=$calculator?:new CriterionCalculator();}
    public function score(array $rule,array $context,array $noDeal):array{return $this->calculator->phpScore($rule,$context,$noDeal);}
}
