<?php

declare(strict_types=1);
$lines = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false || count($lines) !== 1) throw new RuntimeException('Expected one retained native recursion trace.');
$prefix = 'native-recursion-trace: ';
if (!str_starts_with($lines[0], $prefix)) throw new RuntimeException('Invalid trace format.');
$path = json_decode(substr($lines[0], strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
if ($path !== ['example\\realcyclecontroller::getdetails', 'example\\realcyclea::getdetails', 'example\\realcycleb::getdetails', 'example\\realcyclea::getdetails']) throw new RuntimeException('Native trace lost the qualified cycle path.');
echo "Qualified native cycle trace retained through the Mago worker passed\n";
