<?php
declare(strict_types=1);

namespace Sportlauf\Services;

use PDO;

final class FinalistService
{
    public function __construct(private PDO $pdo, private RankingService $rankingService)
    {
    }

    public function propose(int $eventId): array
    {
        $groups = $this->rankingService->qualificationRows($eventId);
        $proposal = [];
        $warnings = [];

        foreach ($groups as $groupName => $rows) {
            $top = array_slice($rows, 0, 3);
            $third = $top[2]['best_qualification_time_tenths'] ?? null;
            $tieRows = [];
            if ($third !== null) {
                $tieRows = array_values(array_filter($rows, static fn (array $row): bool => (int)$row['best_qualification_time_tenths'] === (int)$third));
                if (count($tieRows) > 1) {
                    $warnings[$groupName] = 'Gleichstand auf dem dritten Qualifikationsrang pruefen.';
                }
            }

            $proposal[$groupName] = [
                'rows' => $top,
                'tie_rows' => $tieRows,
                'warning' => $warnings[$groupName] ?? null,
            ];
        }

        return ['groups' => $proposal, 'warnings' => $warnings];
    }

    public function applyProposal(int $eventId): void
    {
        $this->pdo->prepare(
            'UPDATE results r
             JOIN participants p ON p.id = r.participant_id
             SET r.is_finalist = 0, r.final_status = "not_qualified"
             WHERE p.event_id = :event_id'
        )->execute(['event_id' => $eventId]);

        foreach ($this->propose($eventId)['groups'] as $group) {
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

    public function confirm(array $participantIds): void
    {
        if ($participantIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE results SET finalist_confirmed = 1, is_finalist = 1, final_status = 'qualified'
             WHERE participant_id IN ($placeholders)"
        );
        $stmt->execute(array_map('intval', $participantIds));
    }
}
