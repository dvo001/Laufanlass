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

foreach (['1:23.45' => 8345, '01:23,45' => 8345, '83.45' => 8345, '83,45' => 8345,
    '1:23.4' => 8340, '01:23.4' => 8340, '1:23' => 8300, '83.4' => 8340,
    '83' => 8300, '0.01' => 1, '0' => 0, '59.99' => 5999, '1:00.00' => 6000,
    ' 83,05 ' => 8305, '' => null] as $input => $expected) {
    assertSameValue($expected, TimeParser::parse((string)$input), "parse {$input}");
}
foreach ([0 => '00:00.00', 1 => '00:00.01', 5999 => '00:59.99', 6000 => '01:00.00',
    8340 => '01:23.40', 8345 => '01:23.45'] as $value => $formatted) {
    assertSameValue($formatted, TimeParser::format($value), "format {$value}");
    assertSameValue($value, TimeParser::parse($formatted), "round trip {$value}");
}
assertSameValue('', TimeParser::format(null), 'format missing time');
assertSameValue(null, TimeParser::parse(null), 'parse missing time');
assertSameValue(8344, TimeParser::best(8345, 8344), 'best differs by one hundredth');
assertSameValue(8345, TimeParser::best(8345, null), 'best run1 only');
assertSameValue(8345, TimeParser::best(null, 8345), 'best run2 only');
assertSameValue(null, TimeParser::best(null, null), 'best none');

foreach (['abc', '-1:00.0', '1:60.00', '83.456', '1:23.456', '1.2.3'] as $input) {
    try {
        TimeParser::parse($input);
        assertSameValue('exception', 'none', "invalid {$input}");
    } catch (InvalidArgumentException) {
        assertSameValue('exception', 'exception', "invalid {$input}");
    }
}

$finalRows = RankingService::rankFinalGroup([
    ['id' => 4, 'last_name' => 'D', 'first_name' => 'D', 'finalist_confirmed' => 1, 'final_time_hundredths' => null, 'final_status' => 'absent', 'best_qualification_time_hundredths' => 98],
    ['id' => 5, 'last_name' => 'E', 'first_name' => 'E', 'finalist_confirmed' => 0, 'final_time_hundredths' => null, 'final_status' => 'not_qualified', 'best_qualification_time_hundredths' => 115],
    ['id' => 2, 'last_name' => 'B', 'first_name' => 'B', 'finalist_confirmed' => 1, 'final_time_hundredths' => 110, 'final_status' => 'valid', 'best_qualification_time_hundredths' => 95],
    ['id' => 3, 'last_name' => 'C', 'first_name' => 'C', 'finalist_confirmed' => 1, 'final_time_hundredths' => null, 'final_status' => 'present_no_run', 'best_qualification_time_hundredths' => 90],
    ['id' => 1, 'last_name' => 'A', 'first_name' => 'A', 'finalist_confirmed' => 1, 'final_time_hundredths' => 105, 'final_status' => 'valid', 'best_qualification_time_hundredths' => 100],
]);
assertSameValue([1, 2, 3, 4, 5], array_column($finalRows, 'id'), 'present non-runner is third and absent runner loses final place');
assertSameValue(3, $finalRows[2]['rank'], 'present non-runner automatically receives rank three');
assertSameValue('Finale: am Start, nicht gelaufen', $finalRows[2]['ranking_segment'], 'present non-runner gets explicit ranking segment');

$dailyRows = RankingService::rankDailyTimes([
    ['id' => 1, 'last_name' => 'A', 'first_name' => 'A', 'best_qualification_time_hundredths' => 100, 'final_time_hundredths' => 95, 'final_status' => 'valid'],
    ['id' => 2, 'last_name' => 'B', 'first_name' => 'B', 'best_qualification_time_hundredths' => 90, 'final_time_hundredths' => 92, 'final_status' => 'valid'],
    ['id' => 3, 'last_name' => 'C', 'first_name' => 'C', 'best_qualification_time_hundredths' => 98, 'final_time_hundredths' => 80, 'final_status' => 'dsq'],
]);
assertSameValue([2, 1, 3], array_column($dailyRows, 'id'), 'daily prizes use each participant best valid qualification or final time');
assertSameValue(['Qualifikation', 'Finale', 'Qualifikation'], array_column($dailyRows, 'daily_time_source'), 'daily prize identifies the source run');

$preciseRows = RankingService::rankFinalGroup([
    ['id' => 1, 'last_name' => 'A', 'first_name' => 'A', 'finalist_confirmed' => 1, 'final_time_hundredths' => 8345, 'final_status' => 'valid', 'best_qualification_time_hundredths' => 8400],
    ['id' => 2, 'last_name' => 'B', 'first_name' => 'B', 'finalist_confirmed' => 1, 'final_time_hundredths' => 8344, 'final_status' => 'valid', 'best_qualification_time_hundredths' => 8400],
    ['id' => 3, 'last_name' => 'C', 'first_name' => 'C', 'finalist_confirmed' => 1, 'final_time_hundredths' => 8345, 'final_status' => 'valid', 'best_qualification_time_hundredths' => 8400],
]);
assertSameValue([2, 1, 3], array_column($preciseRows, 'id'), 'one hundredth decides final order');
assertSameValue([1, 2, 2], array_column($preciseRows, 'rank'), 'ties require equal hundredths');

$candidateRows = [
    ['id' => 1, 'finalist_confirmed' => 1],
    ['id' => 2, 'finalist_confirmed' => 0],
    ['id' => 3, 'finalist_confirmed' => 1],
    ['id' => 4, 'finalist_confirmed' => 1],
];
assertSameValue([1, 3, 4], Sportlauf\Services\FinalistService::selectionIds($candidateRows), 'saved replacement finalist selection is displayed');
assertSameValue([1, 2, 3, 4], Sportlauf\Services\FinalistService::selectionIds(array_map(static fn (array $row): array => array_merge($row, ['finalist_confirmed' => 1]), $candidateRows)), 'all confirmed finalists remain selected without a three-person limit');
assertSameValue([1, 2, 3], Sportlauf\Services\FinalistService::selectionIds(array_map(static fn (array $row): array => array_merge($row, ['finalist_confirmed' => 0]), $candidateRows)), 'top three are selected before first confirmation');

exit($failures > 0 ? 1 : 0);
