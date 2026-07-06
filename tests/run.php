<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Services/TimeParser.php';
require_once dirname(__DIR__) . '/app/Services/RankingService.php';
require_once dirname(__DIR__) . '/app/Services/FinalistService.php';

use Sportlauf\Services\TimeParser;
use Sportlauf\Services\FinalistService;

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

$rankedRows = [
    ['id' => 1, 'best_qualification_time_tenths' => 100],
    ['id' => 2, 'best_qualification_time_tenths' => 110],
    ['id' => 3, 'best_qualification_time_tenths' => 110],
    ['id' => 4, 'best_qualification_time_tenths' => 120],
];
$selection = FinalistService::selectionForRows($rankedRows, 2);
assertSameValue([1, 2], array_column($selection['rows'], 'id'), 'configurable finalist count');
assertSameValue([2, 3], array_column($selection['tie_rows'], 'id'), 'tie at configurable cutoff');

exit($failures > 0 ? 1 : 0);
