<?php
declare(strict_types=1);

namespace Sportlauf\Services;

use PDO;

final class FinalistService
{
    public function __construct(private PDO $pdo, private RankingService $rankingService)
    {
    }

    public function propose(int $eventId, int $finalistsPerGroup = 3): array
    {
        $finalistsPerGroup = max(1, min(99, $finalistsPerGroup));
        $groups = $this->rankingService->qualificationRows($eventId);
        $proposal = [];
        $warnings = [];

        foreach ($groups as $groupName => $rows) {
            $selection = self::selectionForRows($rows, $finalistsPerGroup);
            if (count($selection['tie_rows']) > 1) {
                $warnings[$groupName] = sprintf('Gleichstand auf Qualifikationsrang %d pruefen.', $finalistsPerGroup);
            }

            $proposal[$groupName] = [
                'rows' => $selection['rows'],
                'tie_rows' => $selection['tie_rows'],
                'warning' => $warnings[$groupName] ?? null,
            ];
        }

        return ['groups' => $proposal, 'warnings' => $warnings];
    }

    public static function selectionForRows(array $rows, int $finalistsPerGroup): array
    {
        $finalistsPerGroup = max(1, min(99, $finalistsPerGroup));
        $top = array_slice($rows, 0, $finalistsPerGroup);
        $lastQualifyingTime = $top[$finalistsPerGroup - 1]['best_qualification_time_tenths'] ?? null;
        $tieRows = $lastQualifyingTime === null ? [] : array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int)$row['best_qualification_time_tenths'] === (int)$lastQualifyingTime
        ));

        return ['rows' => $top, 'tie_rows' => $tieRows];
    }

    public function applyProposal(int $eventId, int $finalistsPerGroup = 3): void
    {
        $this->pdo->prepare(
            'UPDATE results r
             JOIN participants p ON p.id = r.participant_id
             SET r.is_finalist = 0,
                 r.finalist_confirmed = 0,
                 r.final_time_tenths = NULL,
                 r.final_status = "not_qualified"
             WHERE p.event_id = :event_id'
        )->execute(['event_id' => $eventId]);

        foreach ($this->propose($eventId, $finalistsPerGroup)['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $this->markSuggested((int)$row['id']);
            }
        }
    }

    public function markSuggested(int $participantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE results SET is_finalist = 1, final_status = "qualified" WHERE participant_id = :participant_id'
        );
        $stmt->execute(['participant_id' => $participantId]);
    }

    public function confirm(int $eventId, array $participantIds): void
    {
        $this->pdo->prepare(
            'UPDATE results r
             JOIN participants p ON p.id = r.participant_id
             SET r.finalist_confirmed = 0
             WHERE p.event_id = :event_id'
        )->execute(['event_id' => $eventId]);

        if ($participantIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE results r
             JOIN participants p ON p.id = r.participant_id
             SET r.finalist_confirmed = 1, r.is_finalist = 1, r.final_status = 'qualified'
             WHERE p.event_id = ? AND r.participant_id IN ($placeholders)"
        );
        $stmt->execute([$eventId, ...array_map('intval', $participantIds)]);
    }
}
