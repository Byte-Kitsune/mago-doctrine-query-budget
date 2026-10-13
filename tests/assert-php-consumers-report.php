<?php
declare(strict_types=1);
$report=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$evidence=[];
foreach($report['issues'] as $issue) foreach($issue['notes']??[] as $note) {
    if (!str_starts_with($note,'query-budget-evidence: ') || !str_ends_with($issue['code'],'/query-budget-inspection')) continue;
    $value=json_decode(substr($note,strlen('query-budget-evidence: ')),true,512,JSON_THROW_ON_ERROR);
    $evidence[substr($value['entrypoint'],strrpos($value['entrypoint'],'::')+2)]=$value;
}
preg_match_all('/\/\/ budget: (\w+) (\d+) (\d+|null)/',file_get_contents(__DIR__.'/../native/tests/fixtures/php-consumers.php'),$matches,PREG_SET_ORDER);
foreach($matches as [, $method,$lower,$upper]) {
    $actual=$evidence[$method]??null; $expected=[(int)$lower,$upper==='null'?null:(int)$upper];
    if ($actual===null || [$actual['lower_bound'],$actual['upper_bound']]!==$expected) throw new RuntimeException($method.': '.json_encode($actual));
    if ($upper==='null' && $actual['unknown']===[]) throw new RuntimeException('Missing uncertainty for '.$method);
}
echo count($matches)." PHP consumer budgets passed\n";
