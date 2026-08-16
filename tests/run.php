<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Services/TimeParser.php';
require_once dirname(__DIR__) . '/app/Services/RankingService.php';
require_once dirname(__DIR__) . '/app/Services/FinalistService.php';

use Sportlauf\Services\TimeParser;
use Sportlauf\Services\RankingService;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$label} expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . PHP_EOL;
        return;
    }

    echo "OK: {$label}" . PHP_EOL;
}

assertSameValue(834, TimeParser::parse('1:23.4'), 'parse 1:23.4');
assertSameValue(834, TimeParser::parse('01:23.4'), 'parse 01:23.4');
assertSameValue(830, TimeParser::parse('1:23'), 'parse 1:23');
assertSameValue(834, TimeParser::parse('83.4'), 'parse 83.4');
assertSameValue(830, TimeParser::parse('83'), 'parse 83');
assertSameValue('01:23.4', TimeParser::format(834), 'format 834');
assertSameValue(812, TimeParser::best(834, 812), 'best two runs');
assertSameValue(834, TimeParser::best(834, null), 'best run1 only');
assertSameValue(812, TimeParser::best(null, 812), 'best run2 only');
assertSameValue(null, TimeParser::best(null, null), 'best none');

foreach (['abc', '-1:00.0'] as $input) {
    try {
        TimeParser::parse($input);
        assertSameValue('exception', 'none', "invalid {$input}");
    } catch (InvalidArgumentException) {
        assertSameValue('exception', 'exception', "invalid {$input}");
    }
}

$finalRows = RankingService::rankFinalGroup([
    ['id' => 4, 'last_name' => 'D', 'first_name' => 'D', 'finalist_confirmed' => 1, 'final_time_tenths' => null, 'final_status' => 'dns', 'best_qualification_time_tenths' => 98],
    ['id' => 5, 'last_name' => 'E', 'first_name' => 'E', 'finalist_confirmed' => 0, 'final_time_tenths' => null, 'final_status' => 'not_qualified', 'best_qualification_time_tenths' => 115],
    ['id' => 2, 'last_name' => 'B', 'first_name' => 'B', 'finalist_confirmed' => 1, 'final_time_tenths' => 110, 'final_status' => 'valid', 'best_qualification_time_tenths' => 95],
    ['id' => 3, 'last_name' => 'C', 'first_name' => 'C', 'finalist_confirmed' => 1, 'final_time_tenths' => null, 'final_status' => 'dns', 'best_qualification_time_tenths' => 90],
    ['id' => 1, 'last_name' => 'A', 'first_name' => 'A', 'finalist_confirmed' => 1, 'final_time_tenths' => 105, 'final_status' => 'valid', 'best_qualification_time_tenths' => 100],
]);
assertSameValue([1, 2, 3, 4, 5], array_column($finalRows, 'id'), 'final non-starters stay behind final times and before non-finalists');
assertSameValue([90, 98], array_column(array_slice($finalRows, 2, 2), 'ranking_time_tenths'), 'multiple final non-starters use qualification order');

exit($failures > 0 ? 1 : 0);
