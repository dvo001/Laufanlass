<?php
declare(strict_types=1);

namespace Sportlauf\Services;

use PDO;

final class CategoryResolver
{
    public function __construct(private PDO $pdo)
    {
    }

    public function resolve(int $eventId, int $birthYear): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM categories
             WHERE event_id = :event_id AND active = 1 AND :birth_year BETWEEN year_from AND year_to
             ORDER BY sort_order, year_from DESC, id
             LIMIT 1'
        );
        $stmt->execute(['event_id' => $eventId, 'birth_year' => $birthYear]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        return $category ?: null;
    }

    public function validateRange(int $eventId, int $yearFrom, int $yearTo, ?int $ignoreId = null): array
    {
        $errors = [];
        if ($yearFrom > $yearTo) {
            $errors[] = 'Jahrgang von darf nicht groesser sein als Jahrgang bis.';
        }

        $sql = 'SELECT * FROM categories
                WHERE event_id = :event_id
                  AND active = 1
                  AND NOT (year_to < :year_from OR year_from > :year_to)';
        $params = ['event_id' => $eventId, 'year_from' => $yearFrom, 'year_to' => $yearTo];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $errors[] = sprintf('Ueberlappung mit %s (%d-%d).', $row['name'], $row['year_from'], $row['year_to']);
        }

        return $errors;
    }

    public function warningsForGaps(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM categories WHERE event_id = :event_id AND active = 1 ORDER BY year_from'
        );
        $stmt->execute(['event_id' => $eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $warnings = [];

        for ($i = 1; $i < count($rows); $i++) {
            $previous = $rows[$i - 1];
            $current = $rows[$i];
            if ((int)$previous['year_to'] + 1 < (int)$current['year_from']) {
                $warnings[] = sprintf(
                    'Luecke zwischen %s und %s.',
                    $previous['name'],
                    $current['name']
                );
            }
        }

        return $warnings;
    }
}
