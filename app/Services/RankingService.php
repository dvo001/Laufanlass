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
            $finalists = array_values(array_filter($groupRows, static function (array $row): bool {
                return (int)$row['finalist_confirmed'] === 1 && $row['final_time_tenths'] !== null && $row['final_status'] === 'valid';
            }));
            $others = array_values(array_filter($groupRows, static function (array $row): bool {
                return !((int)$row['finalist_confirmed'] === 1 && $row['final_time_tenths'] !== null && $row['final_status'] === 'valid');
            }));

            usort($finalists, self::finalSorter(...));
            usort($others, self::qualificationSorter(...));

            $combined = array_merge($finalists, $others);
            $ranked[$groupKey] = self::assignEndRanks($combined);
        }

        return $ranked;
    }

    public function flatFinalRows(int $eventId): array
    {
        $groups = array_values($this->finalRows($eventId));
        return $groups === [] ? [] : array_merge(...$groups);
    }

    private function rankableRows(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.name AS category_name, c.sort_order,
                    r.run1_time_tenths, r.run2_time_tenths, r.best_qualification_time_tenths,
                    r.is_finalist, r.finalist_confirmed, r.final_time_tenths,
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
            $gender = $row['gender'] === 'female' ? 'Maedchen' : 'Knaben';
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
        $previousSegment = null;

        foreach ($rows as &$row) {
            $position++;
            $isFinal = (int)$row['finalist_confirmed'] === 1 && $row['final_time_tenths'] !== null && $row['final_status'] === 'valid';
            $row['ranking_segment'] = $isFinal ? 'Finale' : 'Qualifikation';
            $row['ranking_time_tenths'] = $isFinal ? $row['final_time_tenths'] : $row['best_qualification_time_tenths'];
            if ($previousTime === null || (int)$row['ranking_time_tenths'] !== (int)$previousTime || $previousSegment !== $row['ranking_segment']) {
                $rank = $position;
                $previousTime = $row['ranking_time_tenths'];
                $previousSegment = $row['ranking_segment'];
            }
            $row['rank'] = $rank;
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
