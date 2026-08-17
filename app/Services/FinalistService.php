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
            if ((int)($rows[0]['has_final'] ?? 1) !== 1) {
                continue;
            }
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['final_status'] !== 'absent'
            ));
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
                'candidates' => $rows,
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
             SET r.is_finalist = 0, r.finalist_confirmed = 0,
                 r.final_time_tenths = NULL, r.final_status = "not_qualified"
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

    public function markAbsentAndPromote(int $eventId, int $participantId): ?int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.category_id, p.gender
                 FROM participants p JOIN results r ON r.participant_id = p.id
                 WHERE p.id = :participant_id AND p.event_id = :event_id
                   AND r.finalist_confirmed = 1
                 FOR UPDATE'
            );
            $stmt->execute(['participant_id' => $participantId, 'event_id' => $eventId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                throw new \InvalidArgumentException('Der abwesende Teilnehmer ist kein bestaetigter Finalist dieses Anlasses.');
            }

            $this->pdo->prepare(
                "UPDATE results
                 SET finalist_confirmed = 0, is_finalist = 0,
                     final_time_tenths = NULL, final_status = 'absent'
                 WHERE participant_id = :participant_id"
            )->execute(['participant_id' => $participantId]);

            $replacement = $this->pdo->prepare(
                "SELECT p.id
                 FROM participants p JOIN results r ON r.participant_id = p.id
                 WHERE p.event_id = :event_id AND p.category_id = :category_id
                   AND p.gender = :gender AND r.finalist_confirmed = 0
                   AND r.qualification_status = 'valid' AND r.final_status <> 'absent'
                 ORDER BY r.best_qualification_time_tenths, p.last_name, p.first_name
                 LIMIT 1 FOR UPDATE"
            );
            $replacement->execute([
                'event_id' => $eventId,
                'category_id' => $group['category_id'],
                'gender' => $group['gender'],
            ]);
            $replacementId = $replacement->fetchColumn();
            if ($replacementId !== false) {
                $this->pdo->prepare(
                    "UPDATE results
                     SET finalist_confirmed = 1, is_finalist = 1, final_status = 'qualified'
                     WHERE participant_id = :participant_id"
                )->execute(['participant_id' => (int)$replacementId]);
            }

            $this->pdo->commit();
            return $replacementId === false ? null : (int)$replacementId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function confirm(int $eventId, array $participantIds): void
    {
        $participantIds = array_values(array_unique(array_map('intval', $participantIds)));
        if ($participantIds === []) {
            $this->clearConfirmed($eventId);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $validation = $this->pdo->prepare(
            "SELECT p.id, p.category_id, p.gender
             FROM participants p
             JOIN categories c ON c.id = p.category_id AND c.has_final = 1
             JOIN results r ON r.participant_id = p.id
             WHERE p.event_id = ? AND p.id IN ($placeholders)
               AND r.qualification_status = 'valid'
             ORDER BY r.best_qualification_time_tenths, p.last_name, p.first_name"
        );
        $validation->execute(array_merge([$eventId], $participantIds));
        $validIds = [];
        $counts = [];
        foreach ($validation->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $group = $row['category_id'] . ':' . $row['gender'];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
            if ($counts[$group] > 3) {
                throw new \InvalidArgumentException('Pro Kategorie und Geschlecht duerfen hoechstens drei Finalisten bestaetigt werden.');
            }
            $validIds[] = (int)$row['id'];
        }
        if (count($validIds) !== count($participantIds)) {
            throw new \InvalidArgumentException('Mindestens eine Finalistenauswahl ist fuer diesen Anlass ungueltig.');
        }

        $this->pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            $params = array_merge([$eventId], $validIds);
            $this->pdo->prepare(
                "UPDATE results r JOIN participants p ON p.id = r.participant_id
                 SET r.finalist_confirmed = 0, r.is_finalist = 0,
                     r.final_time_tenths = NULL, r.final_status = 'not_qualified'
                 WHERE p.event_id = ? AND p.id NOT IN ($placeholders)"
            )->execute($params);
            $stmt = $this->pdo->prepare(
                "UPDATE results
                 SET finalist_confirmed = 1, is_finalist = 1,
                     final_status = IF(final_status IN ('not_qualified', 'absent'), 'qualified', final_status)
                 WHERE participant_id IN ($placeholders)"
            );
            $stmt->execute($validIds);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function clearConfirmed(int $eventId): void
    {
        $this->pdo->prepare(
            'UPDATE results r JOIN participants p ON p.id = r.participant_id
             SET r.finalist_confirmed = 0, r.is_finalist = 0,
                 r.final_time_tenths = NULL, r.final_status = "not_qualified"
             WHERE p.event_id = :event_id'
        )->execute(['event_id' => $eventId]);
    }
}
