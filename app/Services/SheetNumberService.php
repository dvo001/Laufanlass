<?php
declare(strict_types=1);

namespace Sportlauf\Services;

use PDO;

final class SheetNumberService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function next(int $eventId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(CAST(sheet_number AS UNSIGNED)) FROM participants WHERE event_id = :event_id'
        );
        $stmt->execute(['event_id' => $eventId]);
        $next = ((int)$stmt->fetchColumn()) + 1;

        return str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }
}
