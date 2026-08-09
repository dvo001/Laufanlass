<?php
declare(strict_types=1);

use Sportlauf\Services\CategoryResolver;
use Sportlauf\Services\FinalistService;
use Sportlauf\Services\PdfService;
use Sportlauf\Services\RankingService;
use Sportlauf\Services\SheetNumberService;
use Sportlauf\Services\TimeParser;

require_once dirname(__DIR__) . '/app/Services/TimeParser.php';
require_once dirname(__DIR__) . '/app/Services/CategoryResolver.php';
require_once dirname(__DIR__) . '/app/Services/SheetNumberService.php';
require_once dirname(__DIR__) . '/app/Services/RankingService.php';
require_once dirname(__DIR__) . '/app/Services/FinalistService.php';
require_once dirname(__DIR__) . '/app/Services/PdfService.php';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

session_start();

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path, ?string $message = null): never
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
    }
    header('Location: ' . $path);
    exit;
}

function config(): array
{
    $file = dirname(__DIR__) . '/config/database.php';
    if (!is_file($file)) {
        $file = dirname(__DIR__) . '/config/database.example.php';
    }

    return require $file;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function activeEvent(): ?array
{
    $selectedId = (int)($_SESSION['event_id'] ?? 0);
    if ($selectedId > 0) {
        $stmt = db()->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute(['id' => $selectedId]);
        $event = $stmt->fetch();
        if ($event) {
            return $event;
        }
        unset($_SESSION['event_id']);
    }

    $stmt = db()->query("SELECT * FROM events WHERE status = 'active' ORDER BY event_date DESC, id DESC LIMIT 1");
    $event = $stmt->fetch();
    if (!$event) {
        $stmt = db()->query('SELECT * FROM events ORDER BY event_date DESC, id DESC LIMIT 1');
        $event = $stmt->fetch();
    }

    if (!$event) {
        return null;
    }

    $_SESSION['event_id'] = (int)$event['id'];
    return $event;
}

function requireEvent(): array
{
    $event = activeEvent();
    if (!$event) {
        redirect('/events', 'Bitte zuerst einen Anlass erstellen.');
    }

    return $event;
}

function render(string $title, callable $content): void
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $event = activeEvent();
    $links = [
        '/' => 'Dashboard',
        '/events' => 'Anlaesse',
        '/categories' => 'Jahrgangsgruppen',
        '/participants' => 'Teilnehmer',
        '/results' => 'Qualifikationszeiten',
        '/quick-entry' => 'Schnellerfassung',
        '/rankings/qualification' => 'Qualifikation',
        '/rankings' => 'Endrangliste',
        '/sheets/pdf' => 'Laufzettel',
        '/export/csv' => 'CSV Export',
    ];
    if ($event && (int)$event['final_enabled'] === 1) {
        $links = array_slice($links, 0, 7, true)
            + ['/finalists' => 'Finalisten', '/final-results' => 'Finalzeiten']
            + array_slice($links, 7, null, true);
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Laufanlaesse</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">Laufanlaesse</div>
        <?php if ($event): ?>
            <form class="event-switcher" method="post" action="/events/select">
                <label>Anlass
                    <select name="event_id" onchange="this.form.submit()">
                        <?= eventOptions((int)$event['id']) ?>
                    </select>
                </label>
                <noscript><button>Wechseln</button></noscript>
            </form>
        <?php endif; ?>
        <nav class="nav">
            <?php foreach ($links as $href => $label): ?>
                <a class="<?= $path === $href ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <main class="main">
        <h1><?= e($title) ?></h1>
        <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
        <?php $content(); ?>
    </main>
</div>
</body>
</html><?php
}

function eventOptions(?int $selected = null): string
{
    $html = '';
    foreach (db()->query('SELECT id, name, event_date FROM events ORDER BY event_date DESC, id DESC') as $event) {
        $sel = (int)$event['id'] === $selected ? ' selected' : '';
        $html .= sprintf('<option value="%d"%s>%s (%s)</option>', $event['id'], $sel, e($event['name']), e($event['event_date']));
    }
    return $html;
}

function eventStatuses(): array
{
    return [
        'preparation' => 'Vorbereitung',
        'active' => 'Aktiv',
        'closed' => 'Abgeschlossen',
        'archived' => 'Archiviert',
    ];
}

function eventStatusOptions(string $selected = 'preparation'): string
{
    $html = '';
    foreach (eventStatuses() as $value => $label) {
        $sel = $value === $selected ? ' selected' : '';
        $html .= sprintf('<option value="%s"%s>%s</option>', e($value), $sel, e($label));
    }
    return $html;
}

function validEventStatus(string $status): string
{
    return array_key_exists($status, eventStatuses()) ? $status : 'preparation';
}

function eventConfiguration(array $data): array
{
    $qualificationRuns = max(1, min(2, (int)($data['qualification_runs'] ?? 2)));
    $finalEnabled = (int)($data['final_enabled'] ?? 0) === 1 ? 1 : 0;
    $finalistsPerGroup = max(1, min(99, (int)($data['finalists_per_group'] ?? 3)));

    return [
        'qualification_runs' => $qualificationRuns,
        'final_enabled' => $finalEnabled,
        'finalists_per_group' => $finalistsPerGroup,
    ];
}

function requireFinalEvent(): array
{
    $event = requireEvent();
    if ((int)$event['final_enabled'] !== 1) {
        redirect('/', 'Fuer diesen Anlass ist kein Finallauf konfiguriert.');
    }

    return $event;
}

function formatEventDate(?string $date): string
{
    $date = trim((string)$date);
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$parsed) {
        return $date;
    }

    $months = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mrz',
        4 => 'Apr',
        5 => 'Mai',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Aug',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Dez',
    ];

    return sprintf('%02d.%s.%04d', (int)$parsed->format('d'), $months[(int)$parsed->format('n')], (int)$parsed->format('Y'));
}

function eventFileName(array $event, string $document, string $extension): string
{
    $slug = strtolower(strtr((string)$event['name'], ['Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']));
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    if ($slug === '') {
        $slug = 'anlass-' . (int)$event['id'];
    }

    return sprintf('%s-%s-%s.%s', $document, $event['event_date'], $slug, $extension);
}

function categoriesForEvent(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM categories WHERE event_id = :event_id ORDER BY sort_order, year_from DESC, id');
    $stmt->execute(['event_id' => $eventId]);
    return $stmt->fetchAll();
}

function saveParticipant(array $data, ?int $participantId = null): int
{
    $pdo = db();
    $eventId = (int)$data['event_id'];
    $birthYear = (int)$data['birth_year'];
    $gender = $data['gender'];
    if (!in_array($gender, ['female', 'male'], true)) {
        throw new InvalidArgumentException('Geschlecht ist ungueltig.');
    }
    if ($birthYear < 1900 || $birthYear > 2100) {
        throw new InvalidArgumentException('Jahrgang muss vierstellig sein.');
    }

    $category = (new CategoryResolver($pdo))->resolve($eventId, $birthYear);
    $categoryId = $category['id'] ?? null;

    if ($participantId === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO participants
             (event_id, category_id, sheet_number, last_name, first_name, birth_year, gender, school_class, city, notes)
             VALUES (:event_id, :category_id, :sheet_number, :last_name, :first_name, :birth_year, :gender, :school_class, :city, :notes)'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'category_id' => $categoryId,
            'sheet_number' => trim($data['sheet_number']),
            'last_name' => trim($data['last_name']),
            'first_name' => trim($data['first_name']),
            'birth_year' => $birthYear,
            'gender' => $gender,
            'school_class' => trim((string)($data['school_class'] ?? '')),
            'city' => trim((string)($data['city'] ?? '')),
            'notes' => trim((string)($data['notes'] ?? '')),
        ]);
        $participantId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO results (participant_id) VALUES (:participant_id)')
            ->execute(['participant_id' => $participantId]);
        return $participantId;
    }

    $stmt = $pdo->prepare(
        'UPDATE participants SET
         event_id = :event_id, category_id = :category_id, sheet_number = :sheet_number,
         last_name = :last_name, first_name = :first_name, birth_year = :birth_year,
         gender = :gender, school_class = :school_class, city = :city, notes = :notes
         WHERE id = :id'
    );
    $stmt->execute([
        'event_id' => $eventId,
        'category_id' => $categoryId,
        'sheet_number' => trim($data['sheet_number']),
        'last_name' => trim($data['last_name']),
        'first_name' => trim($data['first_name']),
        'birth_year' => $birthYear,
        'gender' => $gender,
        'school_class' => trim((string)($data['school_class'] ?? '')),
        'city' => trim((string)($data['city'] ?? '')),
        'notes' => trim((string)($data['notes'] ?? '')),
        'id' => $participantId,
    ]);

    return $participantId;
}

function saveResult(int $participantId, array $data, int $qualificationRuns = 2): void
{
    $run1 = TimeParser::parse($data['run1_time'] ?? null);
    $run2 = $qualificationRuns > 1 ? TimeParser::parse($data['run2_time'] ?? null) : null;
    $best = TimeParser::best($run1, $run2);
    $status = $best === null ? ($data['qualification_status'] ?? 'no_time') : 'valid';
    if (!in_array($status, ['no_time', 'valid', 'dns', 'dnf', 'dsq'], true)) {
        $status = 'no_time';
    }

    $stmt = db()->prepare(
        'INSERT INTO results (participant_id, run1_time_tenths, run2_time_tenths, best_qualification_time_tenths, qualification_status, notes)
         VALUES (:participant_id, :run1, :run2, :best, :status, :notes)
         ON DUPLICATE KEY UPDATE
           run1_time_tenths = VALUES(run1_time_tenths),
           run2_time_tenths = VALUES(run2_time_tenths),
           best_qualification_time_tenths = VALUES(best_qualification_time_tenths),
           qualification_status = VALUES(qualification_status),
           notes = VALUES(notes)'
    );
    $stmt->execute([
        'participant_id' => $participantId,
        'run1' => $run1,
        'run2' => $run2,
        'best' => $best,
        'status' => $status,
        'notes' => trim((string)($data['result_notes'] ?? '')),
    ]);
}

function renderRankingTable(array $rows, array $event, bool $final = false): void
{
    $hasSecondRun = (int)$event['qualification_runs'] > 1;
    $hasFinal = (int)$event['final_enabled'] === 1;
    ?><table>
        <thead><tr>
            <th>Rang</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Klasse</th><th>Ort</th>
            <th>Lauf 1</th><?php if ($hasSecondRun): ?><th>Lauf 2</th><?php endif; ?><th>Quali</th><?php if ($hasFinal): ?><th>Finale</th><?php endif; ?><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= (int)$row['rank'] ?></td>
                <td><?= e($row['last_name']) ?></td>
                <td><?= e($row['first_name']) ?></td>
                <td><?= e((string)$row['birth_year']) ?></td>
                <td><?= e($row['school_class']) ?></td>
                <td><?= e($row['city']) ?></td>
                <td><?= e(TimeParser::format($row['run1_time_tenths'] !== null ? (int)$row['run1_time_tenths'] : null)) ?></td>
                <?php if ($hasSecondRun): ?><td><?= e(TimeParser::format($row['run2_time_tenths'] !== null ? (int)$row['run2_time_tenths'] : null)) ?></td><?php endif; ?>
                <td><?= e(TimeParser::format((int)$row['best_qualification_time_tenths'])) ?></td>
                <?php if ($hasFinal): ?><td><?= e(TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null)) ?></td><?php endif; ?>
                <td><?= e($final ? ($row['ranking_segment'] ?? $row['final_status']) : $row['qualification_status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table><?php
}

function confirmedFinalistGroups(int $eventId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, c.name AS category_name, c.sort_order, r.best_qualification_time_tenths
         FROM participants p
         JOIN categories c ON c.id = p.category_id
         JOIN results r ON r.participant_id = p.id
         WHERE p.event_id = :event_id
           AND r.finalist_confirmed = 1
         ORDER BY c.sort_order, c.year_from DESC, p.gender, r.best_qualification_time_tenths, p.last_name, p.first_name'
    );
    $stmt->execute(['event_id' => $eventId]);

    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $gender = $row['gender'] === 'female' ? 'Maedchen' : 'Knaben';
        $groups[$row['category_name'] . ' ' . $gender][] = $row;
    }

    return $groups;
}

function renderConfirmedFinalists(array $groups): void
{
    if ($groups === []) {
        echo '<div class="warning">Noch keine Finalisten bestaetigt.</div>';
        return;
    }

    foreach ($groups as $group => $rows) {
        echo '<h2>' . e($group) . '</h2>';
        ?><table>
            <thead><tr>
                <th>Start</th><th>Laufzettel</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Klasse</th><th>Ort</th><th>Qualizeit</th>
            </tr></thead>
            <tbody><?php
            $start = 1;
            foreach ($rows as $row) {
                ?><tr>
                    <td><?= $start++ ?></td>
                    <td><?= e($row['sheet_number']) ?></td>
                    <td><?= e($row['last_name']) ?></td>
                    <td><?= e($row['first_name']) ?></td>
                    <td><?= (int)$row['birth_year'] ?></td>
                    <td><?= e($row['school_class']) ?></td>
                    <td><?= e($row['city']) ?></td>
                    <td><?= e(TimeParser::format((int)$row['best_qualification_time_tenths'])) ?></td>
                </tr><?php
            }
            ?></tbody>
        </table><?php
    }
}

function printablePage(string $title, callable $content): string
{
    ob_start();
    ?><!doctype html><html lang="de"><head><meta charset="utf-8">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    </head><body class="printable"><main class="main"><h1><?= e($title) ?></h1><?php $content(); ?></main></body></html><?php
    return ob_get_clean();
}

function renderRunSheet(array $event, string $sheet): void
{
    $eventName = trim((string)$event['name']) !== '' ? (string)$event['name'] : 'Laufanlass';
    $eventLine = formatEventDate((string)$event['event_date']);
    $qualificationRuns = (int)$event['qualification_runs'];
    $finalistsPerGroup = (int)$event['finalists_per_group'];
    ?>
    <section class="run-sheet">
        <div class="run-sheet-header">
            <?php if (trim((string)$event['logo_path']) !== ''): ?>
                <div class="run-sheet-logo-cell">
                    <img class="run-sheet-logo" src="<?= e($event['logo_path']) ?>" alt="">
                </div>
            <?php endif; ?>
            <div class="run-sheet-title <?= trim((string)$event['logo_path']) === '' ? 'no-logo' : '' ?>">
                <h2>„<?= e($eventName) ?>“</h2>
                <p><?= e($eventLine) ?> · <?= e($event['distance_label']) ?></p>
            </div>
        </div>

        <div class="runner-number">Laeufer Nr. <?= e($sheet) ?></div>

        <div class="sheet-lines participant-lines">
            <div><span>Name:</span><i></i></div>
            <div><span>Vorname:</span><i></i></div>
            <div><span>Jahrgang:</span><i></i></div>
        </div>

        <div class="category-row">
            <strong>Kategorie:</strong>
            <div><b></b> Maedchen / Damen</div>
            <div><b></b> Knaben / Herren</div>
        </div>

        <div class="tear-line"><span>✂︎</span><i></i><span>✂︎</span></div>

        <h3>Zeitenteil · <?= e($sheet) ?></h3>

        <div class="sheet-lines time-lines">
            <div><span>Name:</span><i></i></div>
            <div><span>Vorname:</span><i></i></div>
            <div><span>Lauf 1:</span><i></i><em>Sek.</em></div>
            <?php if ($qualificationRuns > 1): ?><div><span>Lauf 2:</span><i></i><em>Sek.</em></div><?php endif; ?>
        </div>

        <p class="sheet-note">
            <?php if ($qualificationRuns > 1): ?>Es zaehlt die bessere der zwei Zeiten.<?php endif; ?>
            <?php if ((int)$event['final_enabled'] === 1): ?>Die <?= $finalistsPerGroup ?> Schnellsten pro Wertungsgruppe qualifizieren sich fuer das Finale.<?php endif; ?>
        </p>
    </section>
    <?php
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

try {
    if ($path === '/' && $method === 'GET') {
        render('Dashboard', function (): void {
            $event = activeEvent();
            if (!$event) {
                echo '<div class="warning">Noch kein Anlass vorhanden.</div><a class="button" href="/events">Anlass erstellen</a>';
                return;
            }
            $pdo = db();
            $eventId = (int)$event['id'];
            $metrics = [
                'Personen' => 'SELECT COUNT(*) FROM participants WHERE event_id = ?',
                'Mit gueltiger Zeit' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.qualification_status = "valid"',
                'Ohne Zeit' => 'SELECT COUNT(*) FROM participants p LEFT JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND (r.best_qualification_time_tenths IS NULL OR r.id IS NULL)',
                'Ohne Kategorie' => 'SELECT COUNT(*) FROM participants WHERE event_id = ? AND category_id IS NULL',
            ];
            if ((int)$event['final_enabled'] === 1) {
                $metrics += [
                    'Vorgeschlagene Finalisten' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.is_finalist = 1',
                    'Bestaetigte Finalisten' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.finalist_confirmed = 1',
                    'Finalisten ohne Finalzeit' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.finalist_confirmed = 1 AND r.final_time_tenths IS NULL AND r.final_status = "qualified"',
                ];
            }
            ?><div class="panel">
                <h2><?= e($event['name']) ?></h2>
                <p><?= e(formatEventDate($event['event_date'])) ?> · <?= e($event['distance_label']) ?> · <?= (int)$event['qualification_runs'] === 1 ? '1 Qualifikationslauf' : '2 Qualifikationslaeufe' ?><?php if ((int)$event['final_enabled'] === 1): ?> · Finale mit <?= (int)$event['finalists_per_group'] ?> Personen pro Gruppe<?php else: ?> · ohne Finale<?php endif; ?> · Status: <?= e(eventStatuses()[$event['status']] ?? $event['status']) ?></p>
            </div>
            <div class="grid"><?php
            foreach ($metrics as $label => $sql) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$eventId]);
                echo '<div class="metric"><strong>' . (int)$stmt->fetchColumn() . '</strong><span>' . e($label) . '</span></div>';
            }
            ?></div>
            <div class="toolbar">
                <a class="button" href="/rankings/qualification">Qualifikationsrangliste</a>
                <?php if ((int)$event['final_enabled'] === 1): ?>
                    <a class="button" href="/finalists">Finalisten</a>
                    <a class="button" href="/final-results">Finalzeiten</a>
                <?php endif; ?>
                <a class="button" href="/rankings">Endrangliste</a>
            </div><?php
        });
        return;
    }

    if ($path === '/events' && $method === 'POST') {
        $configuration = eventConfiguration($_POST);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO events
                 (name, event_date, distance_label, time_window, qualification_runs, final_enabled, finalists_per_group, logo_path, status, notes)
                 VALUES (:name, :event_date, :distance_label, :time_window, :qualification_runs, :final_enabled, :finalists_per_group, :logo_path, :status, :notes)'
            );
            $stmt->execute([
                'name' => trim($_POST['name']),
                'event_date' => $_POST['event_date'],
                'distance_label' => trim($_POST['distance_label']),
                'time_window' => trim((string)($_POST['time_window'] ?? '')),
                ...$configuration,
                'logo_path' => trim((string)($_POST['logo_path'] ?? '')),
                'status' => validEventStatus((string)$_POST['status']),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ]);
            $eventId = (int)$pdo->lastInsertId();
            $templateId = (int)($_POST['copy_categories_from'] ?? 0);
            if ($templateId > 0 && $templateId !== $eventId) {
                $copy = $pdo->prepare(
                    'INSERT INTO categories (event_id, name, year_from, year_to, sort_order, active)
                     SELECT :event_id, name, year_from, year_to, sort_order, active
                     FROM categories WHERE event_id = :template_id'
                );
                $copy->execute(['event_id' => $eventId, 'template_id' => $templateId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $_SESSION['event_id'] = $eventId;
        redirect('/events', 'Anlass erstellt und ausgewaehlt.');
    }

    if ($path === '/events/update' && $method === 'POST') {
        $configuration = eventConfiguration($_POST);
        $stmt = db()->prepare(
            'UPDATE events SET name = :name, event_date = :event_date, distance_label = :distance_label,
             time_window = :time_window, qualification_runs = :qualification_runs,
             final_enabled = :final_enabled, finalists_per_group = :finalists_per_group,
             logo_path = :logo_path, status = :status, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => trim($_POST['name']),
            'event_date' => $_POST['event_date'],
            'distance_label' => trim($_POST['distance_label']),
            'time_window' => trim((string)($_POST['time_window'] ?? '')),
            ...$configuration,
            'logo_path' => trim((string)($_POST['logo_path'] ?? '')),
            'status' => validEventStatus((string)$_POST['status']),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'id' => (int)$_POST['id'],
        ]);
        if ($configuration['qualification_runs'] === 1) {
            $resetSecondRuns = db()->prepare(
                'UPDATE results r JOIN participants p ON p.id = r.participant_id
                 SET r.run2_time_tenths = NULL,
                     r.best_qualification_time_tenths = r.run1_time_tenths,
                     r.qualification_status = CASE
                         WHEN r.run1_time_tenths IS NOT NULL THEN "valid"
                         WHEN r.qualification_status = "valid" THEN "no_time"
                         ELSE r.qualification_status
                     END
                 WHERE p.event_id = :event_id'
            );
            $resetSecondRuns->execute(['event_id' => (int)$_POST['id']]);
        }
        redirect('/events', 'Anlass aktualisiert.');
    }

    if ($path === '/events/select' && $method === 'POST') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        if ((int)$stmt->fetchColumn() !== 1) {
            redirect('/events', 'Anlass nicht gefunden.');
        }
        $_SESSION['event_id'] = $eventId;
        redirect('/', 'Anlass gewechselt.');
    }

    if ($path === '/events/delete' && $method === 'POST') {
        $stmt = db()->prepare('DELETE FROM events WHERE id = :id');
        $eventId = (int)$_POST['id'];
        $stmt->execute(['id' => $eventId]);
        if ((int)($_SESSION['event_id'] ?? 0) === $eventId) {
            unset($_SESSION['event_id']);
        }

        $message = $stmt->rowCount() > 0 ? 'Anlass geloescht.' : 'Anlass nicht gefunden.';
        redirect('/events', $message);
    }

    if ($path === '/events' && $method === 'GET') {
        render('Anlaesse', function (): void {
            ?><div class="panel"><form method="post" class="grid">
                <label>Name<input required name="name"></label>
                <label>Datum<input required type="date" name="event_date" value="<?= date('Y-m-d') ?>"></label>
                <label>Strecke<input required name="distance_label"></label>
                <label>Zeitfenster<input name="time_window"></label>
                <label>Qualifikationslaeufe<select name="qualification_runs"><option value="1">1 Lauf</option><option value="2" selected>2 Laeufe (beste Zeit)</option></select></label>
                <label>Finallauf<select name="final_enabled"><option value="1" selected>Ja</option><option value="0">Nein</option></select></label>
                <label>Finalplaetze pro Gruppe<input required type="number" min="1" max="99" name="finalists_per_group" value="3"></label>
                <label>Logo-Pfad (optional)<input name="logo_path" placeholder="/assets/img/mein-logo.png"></label>
                <label>Status<select name="status"><?= eventStatusOptions('active') ?></select></label>
                <label>Jahrgangsgruppen uebernehmen<select name="copy_categories_from"><option value="0">Keine</option><?= eventOptions() ?></select></label>
                <label>Bemerkung<textarea name="notes"></textarea></label>
                <div><button>Anlass erstellen</button></div>
            </form></div>
            <h2>Vorhandene Anlaesse</h2><?php
            $selectedId = (int)(activeEvent()['id'] ?? 0);
            foreach (db()->query('SELECT * FROM events ORDER BY event_date DESC, id DESC') as $event) {
                ?><div class="panel event-card">
                    <h3><?= e($event['name']) ?><?= (int)$event['id'] === $selectedId ? ' · ausgewaehlt' : '' ?></h3>
                    <form method="post" action="/events/update" class="grid">
                        <input type="hidden" name="id" value="<?= (int)$event['id'] ?>">
                        <label>Name<input required name="name" value="<?= e($event['name']) ?>"></label>
                        <label>Datum<input required type="date" name="event_date" value="<?= e($event['event_date']) ?>"></label>
                        <label>Strecke<input required name="distance_label" value="<?= e($event['distance_label']) ?>"></label>
                        <label>Zeitfenster<input name="time_window" value="<?= e($event['time_window']) ?>"></label>
                        <label>Qualifikationslaeufe<select name="qualification_runs"><option value="1" <?= (int)$event['qualification_runs'] === 1 ? 'selected' : '' ?>>1 Lauf</option><option value="2" <?= (int)$event['qualification_runs'] === 2 ? 'selected' : '' ?>>2 Laeufe (beste Zeit)</option></select></label>
                        <label>Finallauf<select name="final_enabled"><option value="1" <?= (int)$event['final_enabled'] === 1 ? 'selected' : '' ?>>Ja</option><option value="0" <?= (int)$event['final_enabled'] === 0 ? 'selected' : '' ?>>Nein</option></select></label>
                        <label>Finalplaetze pro Gruppe<input required type="number" min="1" max="99" name="finalists_per_group" value="<?= (int)$event['finalists_per_group'] ?>"></label>
                        <label>Logo-Pfad (optional)<input name="logo_path" value="<?= e($event['logo_path']) ?>" placeholder="/assets/img/mein-logo.png"></label>
                        <label>Status<select name="status"><?= eventStatusOptions((string)$event['status']) ?></select></label>
                        <label>Bemerkung<textarea name="notes"><?= e($event['notes']) ?></textarea></label>
                        <div><button>Speichern</button></div>
                    </form>
                    <div class="toolbar">
                        <?php if ((int)$event['id'] !== $selectedId): ?><form method="post" action="/events/select"><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>"><button class="secondary">Auswaehlen</button></form><?php endif; ?>
                        <form method="post" action="/events/delete" onsubmit="return confirm('Diesen Anlass wirklich loeschen? Kategorien, Teilnehmende und Zeiten werden ebenfalls geloescht.')"><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><button class="danger">Loeschen</button></form>
                    </div>
                </div><?php
            }
        });
        return;
    }

    if ($path === '/categories' && $method === 'POST') {
        $eventId = (int)requireEvent()['id'];
        $from = (int)$_POST['year_from'];
        $to = (int)$_POST['year_to'];
        $active = (int)($_POST['active'] ?? 0);
        $resolver = new CategoryResolver(db());
        $errors = $active ? $resolver->validateRange($eventId, $from, $to) : ($from > $to ? ['Jahrgang von darf nicht groesser sein als Jahrgang bis.'] : []);
        if ($errors !== []) {
            $_SESSION['flash'] = implode(' ', $errors);
            redirect('/categories');
        }
        $stmt = db()->prepare(
            'INSERT INTO categories (event_id, name, year_from, year_to, sort_order, active)
             VALUES (:event_id, :name, :year_from, :year_to, :sort_order, :active)'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'name' => trim($_POST['name']),
            'year_from' => $from,
            'year_to' => $to,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'active' => $active,
        ]);
        redirect('/categories', 'Kategorie gespeichert.');
    }

    if ($path === '/categories/update' && $method === 'POST') {
        $eventId = (int)requireEvent()['id'];
        $categoryId = (int)$_POST['id'];
        $from = (int)$_POST['year_from'];
        $to = (int)$_POST['year_to'];
        $active = (int)($_POST['active'] ?? 0);
        $resolver = new CategoryResolver(db());
        $errors = $active ? $resolver->validateRange($eventId, $from, $to, $categoryId) : ($from > $to ? ['Jahrgang von darf nicht groesser sein als Jahrgang bis.'] : []);
        if ($errors !== []) {
            $_SESSION['flash'] = implode(' ', $errors);
            redirect('/categories');
        }

        $stmt = db()->prepare(
            'UPDATE categories
             SET name = :name, year_from = :year_from, year_to = :year_to, sort_order = :sort_order, active = :active
             WHERE id = :id AND event_id = :event_id'
        );
        $stmt->execute([
            'name' => trim($_POST['name']),
            'year_from' => $from,
            'year_to' => $to,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'active' => $active,
            'id' => $categoryId,
            'event_id' => $eventId,
        ]);

        redirect('/categories', $stmt->rowCount() > 0 ? 'Kategorie aktualisiert.' : 'Kategorie unveraendert oder nicht gefunden.');
    }

    if ($path === '/categories/delete' && $method === 'POST') {
        $eventId = (int)requireEvent()['id'];
        $stmt = db()->prepare('DELETE FROM categories WHERE id = :id AND event_id = :event_id');
        $stmt->execute([
            'id' => (int)$_POST['id'],
            'event_id' => $eventId,
        ]);

        redirect('/categories', $stmt->rowCount() > 0 ? 'Kategorie geloescht.' : 'Kategorie nicht gefunden.');
    }

    if ($path === '/categories' && $method === 'GET') {
        render('Jahrgangsgruppen', function (): void {
            $event = requireEvent();
            $resolver = new CategoryResolver(db());
            foreach ($resolver->warningsForGaps((int)$event['id']) as $warning) {
                echo '<div class="warning">' . e($warning) . '</div>';
            }
            ?><div class="panel"><form method="post" class="grid">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <label>Name<input required name="name"></label>
                <label>Jahrgang von<input required type="number" name="year_from"></label>
                <label>Jahrgang bis<input required type="number" name="year_to"></label>
                <label>Sortierung<input type="number" name="sort_order" value="0"></label>
                <label>Aktiv<select name="active"><option value="1">Ja</option><option value="0">Nein</option></select></label>
                <div><button>Gruppe speichern</button></div>
            </form></div>
            <table><thead><tr><th>Name</th><th>Von</th><th>Bis</th><th>Sortierung</th><th>Wertungsgruppen</th><th>Aktiv</th><th>Aktion</th></tr></thead><tbody><?php
            foreach (categoriesForEvent((int)$event['id']) as $cat) {
                echo '<tr><td colspan="7"><form class="inline-form category-form" method="post" action="/categories/update"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><input type="hidden" name="event_id" value="' . (int)$event['id'] . '"><input required name="name" value="' . e($cat['name']) . '"><input required type="number" name="year_from" value="' . (int)$cat['year_from'] . '"><input required type="number" name="year_to" value="' . (int)$cat['year_to'] . '"><input type="number" name="sort_order" value="' . (int)$cat['sort_order'] . '"><span>' . e($cat['name']) . ' Maedchen<br>' . e($cat['name']) . ' Knaben</span><select name="active"><option value="1"' . ((int)$cat['active'] ? ' selected' : '') . '>Ja</option><option value="0"' . ((int)$cat['active'] ? '' : ' selected') . '>Nein</option></select><button>Speichern</button></form><form class="inline-form" method="post" action="/categories/delete" onsubmit="return confirm(\'Diese Jahrgangsgruppe wirklich loeschen? Zugeordnete Teilnehmende haben danach keine Kategorie mehr.\')"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><input type="hidden" name="event_id" value="' . (int)$event['id'] . '"><button class="danger">Loeschen</button></form></td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/participants' && $method === 'POST') {
        $event = requireEvent();
        $_POST['event_id'] = (int)$event['id'];
        saveParticipant($_POST);
        redirect('/participants/create', 'Teilnehmer gespeichert.');
    }

    if ($path === '/participants/create' && $method === 'GET') {
        render('Teilnehmer erfassen', function (): void {
            $event = requireEvent();
            $sheet = (new SheetNumberService(db()))->next((int)$event['id']);
            ?><div class="panel"><form method="post" action="/participants" class="grid">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <label>Laufzettel-ID<input required name="sheet_number" value="<?= e($sheet) ?>"></label>
                <label>Name<input required name="last_name" autofocus></label>
                <label>Vorname<input required name="first_name"></label>
                <label>Jahrgang<input required type="number" name="birth_year"></label>
                <label>Geschlecht<select name="gender"><option value="female">Maedchen</option><option value="male">Knabe</option></select></label>
                <label>Klasse<input name="school_class"></label>
                <label>Ort<input name="city"></label>
                <label>Bemerkung<textarea name="notes"></textarea></label>
                <div><button>Speichern und naechster Zettel</button></div>
            </form></div><?php
        });
        return;
    }

    if ($path === '/participants' && $method === 'GET') {
        render('Teilnehmer', function (): void {
            $event = requireEvent();
            ?><div class="toolbar"><a class="button" href="/participants/create">Teilnehmer erfassen</a></div>
            <table><thead><tr><th>Zettel</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Geschlecht</th><th>Kategorie</th><th>Klasse</th><th>Ort</th></tr></thead><tbody><?php
            $stmt = db()->prepare(
                'SELECT p.*, c.name AS category_name FROM participants p LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.event_id = :event_id ORDER BY CAST(p.sheet_number AS UNSIGNED), p.sheet_number'
            );
            $stmt->execute(['event_id' => $event['id']]);
            foreach ($stmt as $p) {
                echo '<tr><td>' . e($p['sheet_number']) . '</td><td>' . e($p['last_name']) . '</td><td>' . e($p['first_name']) . '</td><td>' . (int)$p['birth_year'] . '</td><td>' . e($p['gender'] === 'female' ? 'Maedchen' : 'Knabe') . '</td><td>' . e($p['category_name'] ?: 'ohne Kategorie') . '</td><td>' . e($p['school_class']) . '</td><td>' . e($p['city']) . '</td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/results/save' && $method === 'POST') {
        $event = requireEvent();
        $participantId = (int)$_POST['participant_id'];
        $stmt = db()->prepare('SELECT COUNT(*) FROM participants WHERE id = :id AND event_id = :event_id');
        $stmt->execute(['id' => $participantId, 'event_id' => $event['id']]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Teilnehmer gehoert nicht zum ausgewaehlten Anlass.');
        }
        saveResult($participantId, $_POST, (int)$event['qualification_runs']);
        redirect('/results', 'Zeit gespeichert.');
    }

    if ($path === '/results' && $method === 'GET') {
        render('Qualifikationszeiten erfassen', function (): void {
            $event = requireEvent();
            $q = trim((string)($_GET['q'] ?? ''));
            ?><form class="toolbar" method="get">
                <input name="q" value="<?= e($q) ?>" placeholder="Laufzettel-ID, Name, Vorname, Klasse">
                <button>Suchen</button>
            </form><?php
            $sql = 'SELECT p.*, c.name AS category_name, r.run1_time_tenths, r.run2_time_tenths, r.best_qualification_time_tenths, r.qualification_status, r.notes AS result_notes
                    FROM participants p
                    LEFT JOIN categories c ON c.id = p.category_id
                    LEFT JOIN results r ON r.participant_id = p.id
                    WHERE p.event_id = :event_id';
            $params = ['event_id' => $event['id']];
            if ($q !== '') {
                $sql .= ' AND (p.sheet_number LIKE :q OR p.last_name LIKE :q OR p.first_name LIKE :q OR p.school_class LIKE :q)';
                $params['q'] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY CAST(p.sheet_number AS UNSIGNED), p.sheet_number LIMIT 80';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt as $p) {
                ?><div class="panel">
                    <h2><?= e($p['sheet_number']) ?> · <?= e($p['last_name']) ?> <?= e($p['first_name']) ?></h2>
                    <p class="muted"><?= e($p['category_name'] ?: 'ohne Kategorie') ?> · Beste Zeit: <?= e(TimeParser::format($p['best_qualification_time_tenths'] !== null ? (int)$p['best_qualification_time_tenths'] : null)) ?></p>
                    <form method="post" action="/results/save" class="grid">
                        <input type="hidden" name="participant_id" value="<?= (int)$p['id'] ?>">
                        <label>Lauf 1<input name="run1_time" value="<?= e(TimeParser::format($p['run1_time_tenths'] !== null ? (int)$p['run1_time_tenths'] : null)) ?>"></label>
                        <?php if ((int)$event['qualification_runs'] > 1): ?><label>Lauf 2<input name="run2_time" value="<?= e(TimeParser::format($p['run2_time_tenths'] !== null ? (int)$p['run2_time_tenths'] : null)) ?>"></label><?php endif; ?>
                        <label>Status<select name="qualification_status">
                            <?php foreach (['no_time', 'valid', 'dns', 'dnf', 'dsq'] as $status): ?>
                                <option value="<?= e($status) ?>" <?= $p['qualification_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label>Bemerkung<input name="result_notes" value="<?= e($p['result_notes']) ?>"></label>
                        <div><button>Zeit speichern</button></div>
                    </form>
                </div><?php
            }
        });
        return;
    }

    if ($path === '/quick-entry' && $method === 'POST') {
        $event = requireEvent();
        $_POST['event_id'] = (int)$event['id'];
        $participantId = saveParticipant($_POST);
        saveResult($participantId, $_POST, (int)$event['qualification_runs']);
        redirect('/quick-entry', 'Schnellerfassung gespeichert.');
    }

    if ($path === '/quick-entry' && $method === 'GET') {
        render('Schnellerfassung', function (): void {
            $event = requireEvent();
            $sheet = (new SheetNumberService(db()))->next((int)$event['id']);
            ?><div class="panel"><form method="post" class="grid">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <label>Laufzettel-ID<input required name="sheet_number" value="<?= e($sheet) ?>"></label>
                <label>Name<input required name="last_name" autofocus></label>
                <label>Vorname<input required name="first_name"></label>
                <label>Jahrgang<input required type="number" name="birth_year"></label>
                <label>Geschlecht<select name="gender"><option value="female">Maedchen</option><option value="male">Knabe</option></select></label>
                <label>Klasse<input name="school_class"></label>
                <label>Ort<input name="city"></label>
                <label>Lauf 1<input name="run1_time" placeholder="1:23.4"></label>
                <?php if ((int)$event['qualification_runs'] > 1): ?><label>Lauf 2<input name="run2_time" placeholder="83.4"></label><?php endif; ?>
                <div><button>Speichern und naechster Zettel</button></div>
            </form></div><?php
        });
        return;
    }

    if ($path === '/rankings/qualification' && $method === 'GET') {
        render('Qualifikationsrangliste', function (): void {
            $event = requireEvent();
            $groups = (new RankingService(db()))->qualificationRows((int)$event['id']);
            ?><div class="toolbar"><a class="button light" href="/rankings/pdf?type=qualification">Druck/PDF</a></div><?php
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, $event);
            }
        });
        return;
    }

    if ($path === '/finalists/apply' && $method === 'POST') {
        $event = requireFinalEvent();
        (new FinalistService(db(), new RankingService(db())))->applyProposal((int)$event['id'], (int)$event['finalists_per_group']);
        redirect('/finalists', 'Finalistenvorschlag angewendet.');
    }

    if ($path === '/finalists/confirm' && $method === 'POST') {
        $event = requireFinalEvent();
        (new FinalistService(db(), new RankingService(db())))->confirm((int)$event['id'], array_map('intval', $_POST['participant_ids'] ?? []));
        redirect('/finalists?confirmed=1', 'Finalisten bestaetigt.');
    }

    if ($path === '/finalists' && $method === 'GET') {
        render('Finalisten', function (): void {
            $event = requireFinalEvent();
            $proposal = (new FinalistService(db(), new RankingService(db())))->propose((int)$event['id'], (int)$event['finalists_per_group']);
            ?><div class="toolbar">
                <form method="post" action="/finalists/apply"><button>Top <?= (int)$event['finalists_per_group'] ?> vorschlagen</button></form>
                <a class="button light" href="/finalists/pdf">Bestaetigte drucken/PDF</a>
            </div><?php
            if (($_GET['confirmed'] ?? '') === '1') {
                echo '<div class="toolbar"><a class="button" href="/finalists/pdf">Finalistenliste jetzt drucken</a></div>';
            }
            ?><form method="post" action="/finalists/confirm"><?php
            foreach ($proposal['groups'] as $group => $data) {
                echo '<h2>' . e($group) . '</h2>';
                if ($data['warning']) {
                    echo '<div class="warning">' . e($data['warning']) . '</div>';
                }
                echo '<table><thead><tr><th>Bestaetigen</th><th>Name</th><th>Vorname</th><th>Qualizeit</th><th>Hinweis</th></tr></thead><tbody>';
                foreach ($data['rows'] as $row) {
                    $tie = in_array($row, $data['tie_rows'], true) && count($data['tie_rows']) > 1;
                    echo '<tr><td><input type="checkbox" name="participant_ids[]" value="' . (int)$row['id'] . '" checked></td><td>' . e($row['last_name']) . '</td><td>' . e($row['first_name']) . '</td><td>' . e(TimeParser::format((int)$row['best_qualification_time_tenths'])) . '</td><td>' . ($tie ? 'Gleichstand pruefen' : '') . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            ?><div class="toolbar"><button>Auswahl bestaetigen</button></div></form><?php
        });
        return;
    }

    if ($path === '/finalists/pdf' && $method === 'GET') {
        $event = requireFinalEvent();
        $groups = confirmedFinalistGroups((int)$event['id']);
        $html = printablePage('Bestaetigte Finalisten', function () use ($event, $groups): void {
            echo '<p>' . e($event['name']) . ' - ' . e(formatEventDate((string)$event['event_date'])) . '</p>';
            renderConfirmedFinalists($groups);
        });
        PdfService::output($html, eventFileName($event, 'finalistenliste', 'pdf'));
        return;
    }

    if ($path === '/final-results/save' && $method === 'POST') {
        $event = requireFinalEvent();
        foreach ($_POST['final'] ?? [] as $participantId => $data) {
            $time = TimeParser::parse($data['time'] ?? null);
            $status = $time === null ? ($data['status'] ?? 'qualified') : 'valid';
            if (!in_array($status, ['qualified', 'valid', 'dns', 'dnf', 'dsq'], true)) {
                $status = 'qualified';
            }
            $stmt = db()->prepare(
                'UPDATE results r JOIN participants p ON p.id = r.participant_id
                 SET r.final_time_tenths = :time, r.final_status = :status
                 WHERE r.participant_id = :id AND p.event_id = :event_id'
            );
            $stmt->execute(['time' => $time, 'status' => $status, 'id' => (int)$participantId, 'event_id' => $event['id']]);
        }
        redirect('/final-results', 'Finalzeiten gespeichert.');
    }

    if ($path === '/final-results' && $method === 'GET') {
        render('Finalzeiten erfassen', function (): void {
            $event = requireFinalEvent();
            $stmt = db()->prepare(
                'SELECT p.*, c.name AS category_name, r.final_time_tenths, r.final_status
                 FROM participants p JOIN categories c ON c.id = p.category_id JOIN results r ON r.participant_id = p.id
                 WHERE p.event_id = :event_id AND r.finalist_confirmed = 1
                 ORDER BY c.sort_order, p.gender, p.last_name, p.first_name'
            );
            $stmt->execute(['event_id' => $event['id']]);
            ?><form method="post" action="/final-results/save"><table><thead><tr><th>Gruppe</th><th>Name</th><th>Vorname</th><th>Finalzeit</th><th>Status</th></tr></thead><tbody><?php
            foreach ($stmt as $row) {
                ?><tr>
                    <td><?= e($row['category_name']) ?> <?= e($row['gender'] === 'female' ? 'Maedchen' : 'Knaben') ?></td>
                    <td><?= e($row['last_name']) ?></td>
                    <td><?= e($row['first_name']) ?></td>
                    <td><input name="final[<?= (int)$row['id'] ?>][time]" value="<?= e(TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null)) ?>"></td>
                    <td><select name="final[<?= (int)$row['id'] ?>][status]">
                        <?php foreach (['qualified', 'valid', 'dns', 'dnf', 'dsq'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $row['final_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr><?php
            }
            ?></tbody></table><div class="toolbar"><button>Finalzeiten speichern</button></div></form><?php
        });
        return;
    }

    if ($path === '/rankings' && $method === 'GET') {
        render('Endrangliste', function (): void {
            $event = requireEvent();
            $finalEnabled = (int)$event['final_enabled'] === 1;
            $groups = (new RankingService(db()))->endRows((int)$event['id'], $finalEnabled);
            ?><div class="toolbar"><a class="button light" href="/rankings/pdf?type=final">Druck/PDF</a><a class="button light" href="/export/csv">CSV</a></div><?php
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, $event, $finalEnabled);
            }
        });
        return;
    }

    if ($path === '/rankings/pdf' && $method === 'GET') {
        $event = requireEvent();
        $type = $_GET['type'] ?? 'final';
        $service = new RankingService(db());
        $finalEnabled = (int)$event['final_enabled'] === 1;
        $isQualification = $type === 'qualification';
        $groups = $isQualification ? $service->qualificationRows((int)$event['id']) : $service->endRows((int)$event['id'], $finalEnabled);
        $html = printablePage($isQualification ? 'Qualifikationsrangliste' : 'Endrangliste', function () use ($event, $groups, $isQualification, $finalEnabled): void {
            echo '<p>' . e($event['name']) . ' · ' . e(formatEventDate((string)$event['event_date'])) . ' · ' . e($event['distance_label']) . '</p>';
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, $event, !$isQualification && $finalEnabled);
            }
        });
        PdfService::output($html, eventFileName($event, $isQualification ? 'qualifikation' : 'endrangliste', 'pdf'));
        return;
    }

    if ($path === '/sheets/pdf' && $method === 'GET') {
        $event = requireEvent();
        $from = max(1, (int)($_GET['from'] ?? 1));
        $to = max($from, (int)($_GET['to'] ?? 20));
        $html = printablePage('Laufzettel', function () use ($event, $from, $to): void {
            echo '<form class="toolbar no-print" method="get"><input type="number" name="from" value="' . $from . '"><input type="number" name="to" value="' . $to . '"><button>Bereich anzeigen</button></form>';
            echo '<div class="sheet-grid">';
            for ($i = $from; $i <= $to; $i++) {
                $sheet = str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                renderRunSheet($event, $sheet);
            }
            echo '</div>';
        });
        PdfService::output($html, eventFileName($event, 'laufzettel', 'pdf'), 'landscape');
        return;
    }

    if ($path === '/export/csv' && $method === 'GET') {
        $event = requireEvent();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . eventFileName($event, 'endrangliste', 'csv') . '"');
        $out = fopen('php://output', 'w');
        $header = ['Rang', 'Name', 'Vorname', 'Jahrgang', 'Geschlecht', 'Klasse', 'Ort', 'Kategorie', 'Lauf 1'];
        if ((int)$event['qualification_runs'] > 1) {
            $header[] = 'Lauf 2';
        }
        $header[] = 'Beste Qualifikation';
        if ((int)$event['final_enabled'] === 1) {
            array_push($header, 'Finalist', 'Finalzeit');
        }
        $header[] = 'Wertungsstatus';
        fputcsv($out, $header, ';');
        $finalEnabled = (int)$event['final_enabled'] === 1;
        foreach ((new RankingService(db()))->endRows((int)$event['id'], $finalEnabled) as $group => $rows) {
            foreach ($rows as $row) {
                $csvRow = [
                    $row['rank'], $row['last_name'], $row['first_name'], $row['birth_year'],
                    $row['gender'] === 'female' ? 'Maedchen' : 'Knabe', $row['school_class'], $row['city'],
                    $row['category_name'], TimeParser::format($row['run1_time_tenths'] !== null ? (int)$row['run1_time_tenths'] : null),
                ];
                if ((int)$event['qualification_runs'] > 1) {
                    $csvRow[] = TimeParser::format($row['run2_time_tenths'] !== null ? (int)$row['run2_time_tenths'] : null);
                }
                $csvRow[] = TimeParser::format((int)$row['best_qualification_time_tenths']);
                if ((int)$event['final_enabled'] === 1) {
                    $csvRow[] = (int)$row['finalist_confirmed'] === 1 ? 'ja' : 'nein';
                    $csvRow[] = TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null);
                }
                $csvRow[] = $row['ranking_segment'] ?? $row['qualification_status'];
                fputcsv($out, $csvRow, ';');
            }
        }
        fclose($out);
        return;
    }

    http_response_code(404);
    render('Nicht gefunden', static fn () => print '<div class="error">Route nicht gefunden.</div>');
} catch (Throwable $e) {
    http_response_code(500);
    render('Fehler', static fn () => print '<div class="error">' . e($e->getMessage()) . '</div>');
}
