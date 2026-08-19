<?php
declare(strict_types=1);

namespace Sportlauf\Services;

use PDO;

final class RankingService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function qualificationRows(int $eventId): array
    {
        $rows = $this->rankableRows($eventId);
        $groups = $this->groupRows($rows);
        $ranked = [];

        foreach ($groups as $groupKey => $groupRows) {
            usort($groupRows, self::qualificationSorter(...));
            $ranked[$groupKey] = self::assignRanks($groupRows, 'best_qualification_time_tenths');
        }

        return $ranked;
    }

    public function finalRows(int $eventId): array
    {
        $rows = $this->rankableRows($eventId);
        $groups = $this->groupRows($rows);
        $ranked = [];

        foreach ($groups as $groupKey => $groupRows) {
            if ((int)($groupRows[0]['has_final'] ?? 1) !== 1) {
                usort($groupRows, self::qualificationSorter(...));
                $groupRows = self::assignRanks($groupRows, 'best_qualification_time_tenths');
                foreach ($groupRows as &$row) {
                    $row['ranking_segment'] = 'Qualifikation (kein Finale)';
                }
                unset($row);
                $ranked[$groupKey] = $groupRows;
                continue;
            }
            $ranked[$groupKey] = self::rankFinalGroup($groupRows);
        }

        return $ranked;
    }

    public static function rankFinalGroup(array $groupRows): array
    {
        $finalistsWithTime = array_values(array_filter($groupRows, static function (array $row): bool {
            return (int)$row['finalist_confirmed'] === 1 && $row['final_time_tenths'] !== null && $row['final_status'] === 'valid';
        }));
        $finalistsWithoutTime = array_values(array_filter($groupRows, static function (array $row): bool {
            return (int)$row['finalist_confirmed'] === 1
                && $row['final_status'] !== 'absent'
                && !($row['final_time_tenths'] !== null && $row['final_status'] === 'valid');
        }));
        $nonFinalists = array_values(array_filter($groupRows, static function (array $row): bool {
            return (int)$row['finalist_confirmed'] !== 1 || $row['final_status'] === 'absent';
        }));

        usort($finalistsWithTime, self::finalSorter(...));
        usort($finalistsWithoutTime, static function (array $a, array $b): int {
            if ($a['final_status'] === 'present_no_run' || $b['final_status'] === 'present_no_run') {
                return ($a['final_status'] === 'present_no_run' ? 1 : 0) <=> ($b['final_status'] === 'present_no_run' ? 1 : 0);
            }
            return self::qualificationSorter($a, $b);
        });
        usort($nonFinalists, self::qualificationSorter(...));

        return self::assignEndRanks(array_merge($finalistsWithTime, $finalistsWithoutTime, $nonFinalists));
    }

    public function flatFinalRows(int $eventId): array
    {
        $groups = array_values($this->finalRows($eventId));
        return $groups === [] ? [] : array_merge(...$groups);
    }

    public function fastestDailyTimes(int $eventId, int $limit = 3): array
    {
        return array_slice(self::rankDailyTimes($this->rankableRows($eventId)), 0, max(0, $limit));
    }

    public static function rankDailyTimes(array $rows): array
    {
        $ranked = [];
        foreach ($rows as $row) {
            $qualificationTime = $row['best_qualification_time_tenths'] !== null
                ? (int)$row['best_qualification_time_tenths']
                : null;
            $finalTime = $row['final_status'] === 'valid' && $row['final_time_tenths'] !== null
                ? (int)$row['final_time_tenths']
                : null;
            if ($qualificationTime === null && $finalTime === null) {
                continue;
            }

            $row['daily_time_tenths'] = $finalTime !== null && ($qualificationTime === null || $finalTime < $qualificationTime)
                ? $finalTime
                : $qualificationTime;
            $row['daily_time_source'] = $finalTime !== null && $finalTime === $qualificationTime
                ? 'Qualifikation und Finale'
                : ($row['daily_time_tenths'] === $finalTime ? 'Finale' : 'Qualifikation');
            $ranked[] = $row;
        }

        usort($ranked, static fn (array $a, array $b): int =>
            [$a['daily_time_tenths'], $a['last_name'], $a['first_name']]
            <=> [$b['daily_time_tenths'], $b['last_name'], $b['first_name']]
        );
        foreach ($ranked as $index => &$row) {
            $row['award_rank'] = $index + 1;
        }
        unset($row);

        return $ranked;
    }

    private function rankableRows(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.name AS category_name, c.sort_order,
                    r.run1_time_tenths, r.run2_time_tenths, r.best_qualification_time_tenths,
                    r.is_finalist, r.finalist_confirmed, r.final_time_tenths, c.has_final,
                    r.qualification_status, r.final_status
             FROM participants p
             JOIN categories c ON c.id = p.category_id
             JOIN results r ON r.participant_id = p.id
             WHERE p.event_id = :event_id
               AND c.active = 1
               AND r.best_qualification_time_tenths IS NOT NULL
               AND r.qualification_status = "valid"
             ORDER BY c.sort_order, c.name, p.gender, r.best_qualification_time_tenths, p.last_name, p.first_name'
        );
        $stmt->execute(['event_id' => $eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $gender = $row['gender'] === 'female' ? 'Mädchen' : 'Knaben';
            $groups[$row['category_name'] . ' ' . $gender][] = $row;
        }

        return $groups;
    }

    private static function assignRanks(array $rows, string $timeKey): array
    {
        $rank = 0;
        $position = 0;
        $previousTime = null;

        foreach ($rows as &$row) {
            $position++;
            $time = $row[$timeKey];
            if ($previousTime === null || (int)$time !== (int)$previousTime) {
                $rank = $position;
                $previousTime = $time;
            }
            $row['rank'] = $rank;
            $row['ranking_time_tenths'] = $time;
        }

        return $rows;
    }

    private static function assignEndRanks(array $rows): array
    {
        $rank = 0;
        $position = 0;
        $previousTime = null;
        $previousRankBucket = null;

        foreach ($rows as &$row) {
            $position++;
            $isConfirmedFinalist = (int)$row['finalist_confirmed'] === 1;
            $isPresentWithoutRun = $isConfirmedFinalist && $row['final_status'] === 'present_no_run';
            $hasValidFinalTime = $isConfirmedFinalist && $row['final_time_tenths'] !== null && $row['final_status'] === 'valid';
            $rankBucket = $hasValidFinalTime ? 'final-time' : ($isConfirmedFinalist ? 'final-no-time' : 'qualification');
            $row['ranking_segment'] = $hasValidFinalTime
                ? 'Finale'
                : ($isConfirmedFinalist ? 'Finale: ' . $row['final_status'] : 'Qualifikation');
            $row['ranking_time_tenths'] = $hasValidFinalTime ? $row['final_time_tenths'] : $row['best_qualification_time_tenths'];
            if ($previousTime === null || (int)$row['ranking_time_tenths'] !== (int)$previousTime || $previousRankBucket !== $rankBucket) {
                $rank = $position;
                $previousTime = $row['ranking_time_tenths'];
                $previousRankBucket = $rankBucket;
            }
            $row['rank'] = $rank;
            if ($isPresentWithoutRun) {
                $row['rank'] = 3;
                $row['ranking_segment'] = 'Finale: am Start, nicht gelaufen';
            }
        }

        return $rows;
    }

    private static function qualificationSorter(array $a, array $b): int
    {
        return [(int)$a['best_qualification_time_tenths'], $a['last_name'], $a['first_name']]
            <=> [(int)$b['best_qualification_time_tenths'], $b['last_name'], $b['first_name']];
    }

    private static function finalSorter(array $a, array $b): int
    {
        return [(int)$a['final_time_tenths'], $a['last_name'], $a['first_name']]
            <=> [(int)$b['final_time_tenths'], $b['last_name'], $b['first_name']];
    }
}
