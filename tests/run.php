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
    ['id' => 4, 'last_name' => 'D', 'first_name' => 'D', 'finalist_confirmed' => 1, 'final_time_tenths' => null, 'final_status' => 'absent', 'best_qualification_time_tenths' => 98],
    ['id' => 5, 'last_name' => 'E', 'first_name' => 'E', 'finalist_confirmed' => 0, 'final_time_tenths' => null, 'final_status' => 'not_qualified', 'best_qualification_time_tenths' => 115],
    ['id' => 2, 'last_name' => 'B', 'first_name' => 'B', 'finalist_confirmed' => 1, 'final_time_tenths' => 110, 'final_status' => 'valid', 'best_qualification_time_tenths' => 95],
    ['id' => 3, 'last_name' => 'C', 'first_name' => 'C', 'finalist_confirmed' => 1, 'final_time_tenths' => null, 'final_status' => 'present_no_run', 'best_qualification_time_tenths' => 90],
    ['id' => 1, 'last_name' => 'A', 'first_name' => 'A', 'finalist_confirmed' => 1, 'final_time_tenths' => 105, 'final_status' => 'valid', 'best_qualification_time_tenths' => 100],
]);
assertSameValue([1, 2, 3, 4, 5], array_column($finalRows, 'id'), 'present non-runner is third and absent runner loses final place');
assertSameValue(3, $finalRows[2]['rank'], 'present non-runner automatically receives rank three');
assertSameValue('Finale: am Start, nicht gelaufen', $finalRows[2]['ranking_segment'], 'present non-runner gets explicit ranking segment');

$dailyRows = RankingService::rankDailyTimes([
    ['id' => 1, 'last_name' => 'A', 'first_name' => 'A', 'best_qualification_time_tenths' => 100, 'final_time_tenths' => 95, 'final_status' => 'valid'],
    ['id' => 2, 'last_name' => 'B', 'first_name' => 'B', 'best_qualification_time_tenths' => 90, 'final_time_tenths' => 92, 'final_status' => 'valid'],
    ['id' => 3, 'last_name' => 'C', 'first_name' => 'C', 'best_qualification_time_tenths' => 98, 'final_time_tenths' => 80, 'final_status' => 'dsq'],
]);
assertSameValue([2, 1, 3], array_column($dailyRows, 'id'), 'daily prizes use each participant best valid qualification or final time');
assertSameValue(['Qualifikation', 'Finale', 'Qualifikation'], array_column($dailyRows, 'daily_time_source'), 'daily prize identifies the source run');

$candidateRows = [
    ['id' => 1, 'finalist_confirmed' => 1],
    ['id' => 2, 'finalist_confirmed' => 0],
    ['id' => 3, 'finalist_confirmed' => 1],
    ['id' => 4, 'finalist_confirmed' => 1],
];
assertSameValue([1, 3, 4], Sportlauf\Services\FinalistService::selectionIds($candidateRows), 'saved replacement finalist selection is displayed');
assertSameValue([1, 2, 3], Sportlauf\Services\FinalistService::selectionIds(array_map(static fn (array $row): array => array_merge($row, ['finalist_confirmed' => 0]), $candidateRows)), 'top three are selected before first confirmation');

exit($failures > 0 ? 1 : 0);
