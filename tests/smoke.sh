#!/bin/sh
set -eu
cd tests/corpus
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level warning > /tmp/mago-doctrine-query-budget-report.json || test "$?" -eq 1
php -r '$r=json_decode(file_get_contents("/tmp/mago-doctrine-query-budget-report.json"),true,512,JSON_THROW_ON_ERROR); $found=[]; foreach($r["issues"] as $i){ $found[$i["code"]][]=$i; } foreach(["query-budget-exceeded","recursive-call","query-budget-incomplete"] as $code){ if(empty($found["byte-kitsune/doctrine-query-budget/".$code])) exit(1); } $budget=$found["byte-kitsune/doctrine-query-budget/query-budget-exceeded"][0]; $note=$budget["notes"][0]??""; $data=json_decode(substr($note,strlen("query-budget-evidence: ")),true,512,JSON_THROW_ON_ERROR); if($data["lower_bound"]!==4 || $data["upper_bound"]!==4) exit(1); echo "Mago query-budget corpus passed\n";'
