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

function absoluteUrl(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $path;
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
    $stmt = db()->query("SELECT * FROM events WHERE status = 'active' ORDER BY event_date DESC, id DESC LIMIT 1");
    $event = $stmt->fetch();
    if ($event) {
        return $event;
    }

    $stmt = db()->query('SELECT * FROM events ORDER BY event_date DESC, id DESC LIMIT 1');
    $event = $stmt->fetch();
    return $event ?: null;
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
    $links = [
        '/' => 'Dashboard',
        '/events' => 'Anlass',
        '/categories' => 'Jahrgangsgruppen',
        '/participants' => 'Teilnehmer',
        '/results' => 'Qualifikationszeiten',
        '/quick-entry' => 'Schnellerfassung',
        '/rankings/qualification' => 'Qualifikation',
        '/finalists' => 'Finalisten',
        '/final-results' => 'Finalzeiten',
        '/mobile-final-results' => 'Mobile Finalerfassung',
        '/rankings' => 'Endrangliste',
        '/sheets/pdf' => 'Laufzettel',
        '/export/csv' => 'CSV Export',
    ];

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Sportlauf</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="<?= $path === '/mobile-final-results' ? 'mobile-final-page' : '' ?>">
<div class="app">
    <aside class="sidebar">
        <div class="brand">Sportlauf</div>
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

function saveResult(int $participantId, array $data): void
{
    $run1 = TimeParser::parse($data['run1_time'] ?? null);
    $run2 = TimeParser::parse($data['run2_time'] ?? null);
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

function renderRankingTable(array $rows, bool $final = false): void
{
    if ($final) {
        ?><table>
            <thead><tr>
                <th>Rang</th><th>Name</th><th>Vorname</th><th>Jahrgang</th><th>Beste Qualifikationszeit</th><th><strong>Finalzeit</strong></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $finalValue = $row['final_time_tenths'] !== null ? TimeParser::format((int)$row['final_time_tenths']) : strtoupper((string)$row['final_status']); ?>
                <tr>
                    <td><?= (int)$row['rank'] ?></td>
                    <td><?= e($row['last_name']) ?></td>
                    <td><?= e($row['first_name']) ?></td>
                    <td><?= e((string)$row['birth_year']) ?></td>
                    <td><?= e(TimeParser::format((int)$row['best_qualification_time_tenths'])) ?></td>
                    <td><strong><?= e($finalValue) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table><?php
        return;
    }

    ?><table>
        <thead><tr>
            <th>Rang</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Klasse</th><th>Ort</th>
            <th>Lauf 1</th><th>Lauf 2</th><th>Quali</th><th>Finale</th><th>Status</th>
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
                <td><?= e(TimeParser::format($row['run2_time_tenths'] !== null ? (int)$row['run2_time_tenths'] : null)) ?></td>
                <td><?= e(TimeParser::format((int)$row['best_qualification_time_tenths'])) ?></td>
                <td><?= e(TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null)) ?></td>
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

function renderFullFinalistList(array $groups): void
{
    if ($groups === []) {
        echo '<div class="warning">Keine Finalkategorien mit gueltigen Qualifikationszeiten vorhanden.</div>';
        return;
    }

    foreach ($groups as $group => $data) {
        echo '<h2>' . e($group) . '</h2>';
        ?><table>
            <thead><tr>
                <th>Quali-Rang</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Status</th><th>Laufzettel</th><th>Qualizeit</th>
            </tr></thead>
            <tbody><?php
            foreach ($data['candidates'] as $row) {
                $rank = (int)$row['rank'];
                $confirmed = (int)$row['finalist_confirmed'] === 1;
                $status = $confirmed
                    ? ($rank > 3 ? 'Finalist (nachgerueckt)' : 'Finalist')
                    : ($rank > 3 ? 'Nachruecker' : 'Vorgeschlagen');
                ?><tr>
                    <td><?= $rank ?></td>
                    <td><?= e($row['last_name']) ?></td>
                    <td><?= e($row['first_name']) ?></td>
                    <td><?= (int)$row['birth_year'] ?></td>
                    <td><?= e($status) ?></td>
                    <td><?= e($row['sheet_number']) ?></td>
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
    $eventName = trim((string)$event['name']) !== '' ? (string)$event['name'] : 'dae schnaellschti Winkler 2026';
    $eventLine = formatEventDate((string)$event['event_date']);
    ?>
    <section class="run-sheet">
        <div class="run-sheet-header">
            <div class="run-sheet-logo-cell">
                <img class="run-sheet-logo" src="/assets/img/laufblatt-logo.png" alt="">
            </div>
            <div class="run-sheet-title">
                <h2>„<?= e($eventName) ?>“</h2>
                <p><?= e($eventLine !== '' ? $eventLine : 'Samstag, September') ?></p>
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
            <div><span>Lauf 2:</span><i></i><em>Sek.</em></div>
        </div>

        <p class="sheet-note">Es zaehlt die bessere der zwei Zeiten. Die drei schnellsten pro Wertungsgruppe qualifizieren sich fuer das Finale.</p>
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
                'Vorgeschlagene Finalisten' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.is_finalist = 1',
                'Bestaetigte Finalisten' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.finalist_confirmed = 1',
                'Finalisten ohne Finalzeit' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.finalist_confirmed = 1 AND r.final_time_tenths IS NULL AND r.final_status = "qualified"',
            ];
            ?><div class="panel">
                <h2><?= e($event['name']) ?></h2>
                <p><?= e($event['event_date']) ?> · <?= e($event['distance_label']) ?> · Status: <?= e($event['status']) ?></p>
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
                <a class="button" href="/finalists">Finalisten</a>
                <a class="button" href="/final-results">Finalzeiten</a>
                <a class="button" href="/rankings">Endrangliste</a>
            </div><?php
        });
        return;
    }

    if ($path === '/events' && $method === 'POST') {
        $stmt = db()->prepare(
            'INSERT INTO events (name, event_date, distance_label, time_window, status, notes)
             VALUES (:name, :event_date, :distance_label, :time_window, :status, :notes)'
        );
        $stmt->execute([
            'name' => trim($_POST['name']),
            'event_date' => $_POST['event_date'],
            'distance_label' => trim($_POST['distance_label']),
            'time_window' => trim((string)($_POST['time_window'] ?? '')),
            'status' => validEventStatus((string)$_POST['status']),
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ]);
        redirect('/events', 'Anlass gespeichert.');
    }

    if ($path === '/events/update' && $method === 'POST') {
        $stmt = db()->prepare('UPDATE events SET status = :status WHERE id = :id');
        $stmt->execute([
            'status' => validEventStatus((string)$_POST['status']),
            'id' => (int)$_POST['id'],
        ]);
        redirect('/events', 'Status aktualisiert.');
    }

    if ($path === '/events/delete' && $method === 'POST') {
        $stmt = db()->prepare('DELETE FROM events WHERE id = :id');
        $stmt->execute(['id' => (int)$_POST['id']]);

        $message = $stmt->rowCount() > 0 ? 'Anlass geloescht.' : 'Anlass nicht gefunden.';
        redirect('/events', $message);
    }

    if ($path === '/events' && $method === 'GET') {
        render('Anlass', function (): void {
            ?><div class="panel"><form method="post" class="grid">
                <label>Name<input required name="name"></label>
                <label>Datum<input required type="date" name="event_date" value="<?= date('Y-m-d') ?>"></label>
                <label>Strecke<input required name="distance_label"></label>
                <label>Zeitfenster<input name="time_window"></label>
                <label>Status<select name="status"><?= eventStatusOptions('active') ?></select></label>
                <label>Bemerkung<textarea name="notes"></textarea></label>
                <div><button>Anlass speichern</button></div>
            </form></div>
            <table><thead><tr><th>Name</th><th>Datum</th><th>Strecke</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php
            foreach (db()->query('SELECT * FROM events ORDER BY event_date DESC, id DESC') as $event) {
                echo '<tr><td>' . e($event['name']) . '</td><td>' . e($event['event_date']) . '</td><td>' . e($event['distance_label']) . '</td><td><form class="inline-form" method="post" action="/events/update"><input type="hidden" name="id" value="' . (int)$event['id'] . '"><select name="status">' . eventStatusOptions((string)$event['status']) . '</select><button>Status aktualisieren</button></form></td><td><form class="inline-form" method="post" action="/events/delete" onsubmit="return confirm(\'Diesen Anlass wirklich loeschen? Kategorien, Teilnehmende und Zeiten werden ebenfalls geloescht.\')"><input type="hidden" name="id" value="' . (int)$event['id'] . '"><button class="danger">Loeschen</button></form></td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/categories' && $method === 'POST') {
        $eventId = (int)$_POST['event_id'];
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
            'INSERT INTO categories (event_id, name, year_from, year_to, sort_order, active, has_final)
             VALUES (:event_id, :name, :year_from, :year_to, :sort_order, :active, :has_final)'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'name' => trim($_POST['name']),
            'year_from' => $from,
            'year_to' => $to,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'active' => $active,
            'has_final' => (int)($_POST['has_final'] ?? 0),
        ]);
        redirect('/categories', 'Kategorie gespeichert.');
    }

    if ($path === '/categories/update' && $method === 'POST') {
        $eventId = (int)$_POST['event_id'];
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
             SET name = :name, year_from = :year_from, year_to = :year_to,
                 sort_order = :sort_order, active = :active, has_final = :has_final
             WHERE id = :id AND event_id = :event_id'
        );
        $stmt->execute([
            'name' => trim($_POST['name']),
            'year_from' => $from,
            'year_to' => $to,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'active' => $active,
            'has_final' => (int)($_POST['has_final'] ?? 0),
            'id' => $categoryId,
            'event_id' => $eventId,
        ]);

        redirect('/categories', $stmt->rowCount() > 0 ? 'Kategorie aktualisiert.' : 'Kategorie unveraendert oder nicht gefunden.');
    }

    if ($path === '/categories/delete' && $method === 'POST') {
        $stmt = db()->prepare('DELETE FROM categories WHERE id = :id AND event_id = :event_id');
        $stmt->execute([
            'id' => (int)$_POST['id'],
            'event_id' => (int)$_POST['event_id'],
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
                <label>Finallauf<select name="has_final"><option value="1">Ja</option><option value="0">Nein, Vorlauf werten</option></select></label>
                <div><button>Gruppe speichern</button></div>
            </form></div>
            <table><thead><tr><th>Name</th><th>Von</th><th>Bis</th><th>Sortierung</th><th>Wertungsgruppen</th><th>Aktiv</th><th>Finale</th><th>Aktion</th></tr></thead><tbody><?php
            foreach (categoriesForEvent((int)$event['id']) as $cat) {
                echo '<tr><td colspan="8"><form class="inline-form category-form" method="post" action="/categories/update"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><input type="hidden" name="event_id" value="' . (int)$event['id'] . '"><input required name="name" value="' . e($cat['name']) . '"><input required type="number" name="year_from" value="' . (int)$cat['year_from'] . '"><input required type="number" name="year_to" value="' . (int)$cat['year_to'] . '"><input type="number" name="sort_order" value="' . (int)$cat['sort_order'] . '"><span>' . e($cat['name']) . ' Maedchen<br>' . e($cat['name']) . ' Knaben</span><select name="active"><option value="1"' . ((int)$cat['active'] ? ' selected' : '') . '>Ja</option><option value="0"' . ((int)$cat['active'] ? '' : ' selected') . '>Nein</option></select><select name="has_final"><option value="1"' . ((int)$cat['has_final'] ? ' selected' : '') . '>Ja</option><option value="0"' . ((int)$cat['has_final'] ? '' : ' selected') . '>Nein, Vorlauf</option></select><button>Speichern</button></form><form class="inline-form" method="post" action="/categories/delete" onsubmit="return confirm(\'Diese Jahrgangsgruppe wirklich loeschen? Zugeordnete Teilnehmende haben danach keine Kategorie mehr.\')"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><input type="hidden" name="event_id" value="' . (int)$event['id'] . '"><button class="danger">Loeschen</button></form></td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/participants' && $method === 'POST') {
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
        saveResult((int)$_POST['participant_id'], $_POST);
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
                        <label>Lauf 2<input name="run2_time" value="<?= e(TimeParser::format($p['run2_time_tenths'] !== null ? (int)$p['run2_time_tenths'] : null)) ?>"></label>
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
        $participantId = saveParticipant($_POST);
        saveResult($participantId, $_POST);
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
                <label>Lauf 2<input name="run2_time" placeholder="83.4"></label>
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
                renderRankingTable($rows);
            }
        });
        return;
    }

    if ($path === '/finalists/confirm' && $method === 'POST') {
        $event = requireEvent();
        (new FinalistService(db(), new RankingService(db())))->confirm((int)$event['id'], array_map('intval', $_POST['participant_ids'] ?? []));
        redirect('/finalists?confirmed=1', 'Finalisten bestaetigt.');
    }

    if ($path === '/finalists' && $method === 'GET') {
        render('Finalisten', function (): void {
            $event = requireEvent();
            $proposal = (new FinalistService(db(), new RankingService(db())))->propose((int)$event['id']);
            ?><div class="toolbar">
                <a class="button light" href="/finalists/pdf">Finalisten inkl. Nachruecker drucken/PDF</a>
            </div><?php
            ?><form method="post" action="/finalists/confirm"><?php
            foreach ($proposal['groups'] as $group => $data) {
                echo '<h2>' . e($group) . '</h2>';
                if ($data['warning']) {
                    echo '<div class="warning">' . e($data['warning']) . '</div>';
                }
                echo '<table><thead><tr><th>Bestaetigen</th><th>Name</th><th>Vorname</th><th>Qualizeit</th><th>Hinweis</th></tr></thead><tbody>';
                foreach ($data['candidates'] as $index => $row) {
                    $tie = in_array($row, $data['tie_rows'], true) && count($data['tie_rows']) > 1;
                    $hint = $tie ? 'Gleichstand pruefen' : ($index >= 3 ? 'Nachruecker Rang ' . ($index + 1) : 'Direkt qualifiziert');
                    echo '<tr><td><input type="checkbox" name="participant_ids[]" value="' . (int)$row['id'] . '"' . ($index < 3 ? ' checked' : '') . '></td><td>' . e($row['last_name']) . '</td><td>' . e($row['first_name']) . '</td><td>' . e(TimeParser::format((int)$row['best_qualification_time_tenths'])) . '</td><td>' . e($hint) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            ?><div class="toolbar"><button>Auswahl bestaetigen</button></div></form><?php
        });
        return;
    }

    if ($path === '/finalists/pdf' && $method === 'GET') {
        $event = requireEvent();
        $groups = (new FinalistService(db(), new RankingService(db())))->propose((int)$event['id'])['groups'];
        $html = printablePage('Finalistenliste', function () use ($event, $groups): void {
            echo '<p>' . e($event['name']) . ' - ' . e(formatEventDate((string)$event['event_date'])) . '</p>';
            renderFullFinalistList($groups);
        });
        PdfService::output($html, 'finalistenliste.pdf');
        return;
    }

    if ($path === '/final-results/save' && $method === 'POST') {
        $event = requireEvent();
        $finalistService = new FinalistService(db(), new RankingService(db()));
        foreach ($_POST['final'] ?? [] as $participantId => $data) {
            $time = TimeParser::parse($data['time'] ?? null);
            $status = $time === null ? ($data['status'] ?? 'qualified') : 'valid';
            if (!in_array($status, ['qualified', 'valid', 'present_no_run', 'absent', 'dnf', 'dsq'], true)) {
                $status = 'qualified';
            }
            if ($status === 'absent') {
                $finalistService->markAbsentAndPromote((int)$event['id'], (int)$participantId);
                continue;
            }
            $stmt = db()->prepare('UPDATE results SET final_time_tenths = :time, final_status = :status WHERE participant_id = :id');
            $stmt->execute(['time' => $time, 'status' => $status, 'id' => (int)$participantId]);
        }
        redirect('/final-results', 'Finalzeiten gespeichert.');
    }

    if ($path === '/mobile-final-results/save' && $method === 'POST') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $participantId = (int)($_POST['participant_id'] ?? 0);
        $gender = (string)($_POST['gender'] ?? '');
        $action = (string)($_POST['action'] ?? 'save');
        if (!in_array($gender, ['female', 'male'], true)) {
            throw new InvalidArgumentException('Ungueltige Finalkategorie.');
        }

        $statusActions = ['present_no_run', 'absent'];
        $time = in_array($action, $statusActions, true) ? null : TimeParser::parse($_POST['time'] ?? null);
        if (!in_array($action, $statusActions, true) && $time === null) {
            throw new InvalidArgumentException('Bitte eine Finalzeit eingeben oder einen Status waehlen.');
        }
        $status = in_array($action, $statusActions, true) ? $action : 'valid';
        if ($status === 'absent') {
            (new FinalistService(db(), new RankingService(db())))->markAbsentAndPromote($eventId, $participantId);
        } else {
            $stmt = db()->prepare(
            'UPDATE results r JOIN participants p ON p.id = r.participant_id
             SET r.final_time_tenths = :time, r.final_status = :status
             WHERE r.participant_id = :participant_id AND r.finalist_confirmed = 1
               AND p.event_id = :event_id AND p.category_id = :category_id AND p.gender = :gender'
        );
            $stmt->execute([
                'time' => $time,
                'status' => $status,
                'participant_id' => $participantId,
                'event_id' => $eventId,
                'category_id' => $categoryId,
                'gender' => $gender,
            ]);
        }

        $query = http_build_query(['event_id' => $eventId, 'category_id' => $categoryId, 'gender' => $gender]);
        redirect('/mobile-final-results?' . $query, $action === 'save' ? 'Finalzeit gespeichert.' : 'Finalstatus gespeichert.');
    }

    if ($path === '/mobile-final-results/qr' && $method === 'GET') {
        $eventId = (int)($_GET['event_id'] ?? 0);
        $stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Anlass fuer QR-Code nicht gefunden.');
        }

        $target = absoluteUrl('/mobile-final-results?event_id=' . $eventId);
        $qrCode = Endroid\QrCode\QrCode::create($target)
            ->setErrorCorrectionLevel(Endroid\QrCode\ErrorCorrectionLevel::Medium)
            ->setSize(360)
            ->setMargin(16);
        $result = (new Endroid\QrCode\Writer\SvgWriter())->write($qrCode);
        header('Content-Type: ' . $result->getMimeType());
        header('Cache-Control: no-store');
        echo $result->getString();
        exit;
    }

    if ($path === '/mobile-final-results' && $method === 'GET') {
        $events = db()->query('SELECT id, name, event_date FROM events ORDER BY event_date DESC, id DESC')->fetchAll();
        $eventIds = array_map(static fn (array $event): int => (int)$event['id'], $events);
        $requestedEventId = (int)($_GET['event_id'] ?? 0);
        $activeEventId = (int)(activeEvent()['id'] ?? 0);
        $eventId = in_array($requestedEventId, $eventIds, true)
            ? $requestedEventId
            : (in_array($activeEventId, $eventIds, true) ? $activeEventId : ($eventIds[0] ?? 0));

        $groups = [];
        if ($eventId > 0) {
            $stmt = db()->prepare(
                'SELECT DISTINCT c.id, c.name, c.sort_order, p.gender
                 FROM categories c
                 JOIN participants p ON p.category_id = c.id
                 JOIN results r ON r.participant_id = p.id AND r.finalist_confirmed = 1
                 WHERE c.event_id = :event_id AND c.active = 1
                 ORDER BY c.sort_order, c.name, p.gender'
            );
            $stmt->execute(['event_id' => $eventId]);
            $groups = $stmt->fetchAll();
        }

        $categoryId = (int)($_GET['category_id'] ?? 0);
        $gender = (string)($_GET['gender'] ?? '');
        $selectedGroup = null;
        foreach ($groups as $group) {
            if ((int)$group['id'] === $categoryId && $group['gender'] === $gender) {
                $selectedGroup = $group;
                break;
            }
        }
        if ($selectedGroup === null && $groups !== []) {
            $selectedGroup = $groups[0];
            $categoryId = (int)$selectedGroup['id'];
            $gender = (string)$selectedGroup['gender'];
        }

        $finalists = [];
        if ($selectedGroup !== null) {
            $stmt = db()->prepare(
                'SELECT p.id, p.last_name, p.first_name, p.birth_year,
                        r.best_qualification_time_tenths, r.final_time_tenths, r.final_status
                 FROM participants p JOIN results r ON r.participant_id = p.id
                 WHERE p.event_id = :event_id AND p.category_id = :category_id
                   AND p.gender = :gender AND r.finalist_confirmed = 1
                 ORDER BY r.best_qualification_time_tenths, p.last_name, p.first_name'
            );
            $stmt->execute(['event_id' => $eventId, 'category_id' => $categoryId, 'gender' => $gender]);
            $finalists = $stmt->fetchAll();
        }

        render('Mobile Finalerfassung', function () use ($events, $eventId, $groups, $categoryId, $gender, $finalists): void {
            ?><div class="mobile-final-entry">
                <?php if ($events === []): ?><div class="warning">Es ist noch kein Anlass vorhanden.</div><?php else: ?>
                    <form method="get" action="/mobile-final-results" class="mobile-final-filter panel">
                        <label>Anlass<select name="event_id" onchange="this.form.submit()">
                            <?php foreach ($events as $event): ?><option value="<?= (int)$event['id'] ?>" <?= (int)$event['id'] === $eventId ? 'selected' : '' ?>><?= e($event['name']) ?> (<?= e(formatEventDate($event['event_date'])) ?>)</option><?php endforeach; ?>
                        </select></label>
                        <label>Kategorie<select name="group" onchange="const [categoryId, gender] = this.value.split(':'); this.form.category_id.value = categoryId; this.form.gender.value = gender; this.form.submit()">
                            <?php foreach ($groups as $group): ?>
                                <?php $selected = (int)$group['id'] === $categoryId && $group['gender'] === $gender; ?>
                                <option value="<?= (int)$group['id'] ?>:<?= e($group['gender']) ?>" <?= $selected ? 'selected' : '' ?>><?= e($group['name']) ?> · <?= $group['gender'] === 'female' ? 'Maedchen' : 'Knaben' ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                        <input type="hidden" name="gender" value="<?= e($gender) ?>">
                        <noscript><button>Auswahl anzeigen</button></noscript>
                    </form>
                    <?php if ($groups === []): ?><div class="warning">Fuer diesen Anlass sind noch keine Finalisten bestaetigt.</div><?php endif; ?>
                    <div class="mobile-final-list">
                        <?php foreach ($finalists as $runner): ?>
                            <form method="post" action="/mobile-final-results/save" class="mobile-final-runner panel">
                                <input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="category_id" value="<?= $categoryId ?>"><input type="hidden" name="gender" value="<?= e($gender) ?>"><input type="hidden" name="participant_id" value="<?= (int)$runner['id'] ?>">
                                <div class="mobile-final-runner-name"><strong><?= e($runner['last_name']) ?> <?= e($runner['first_name']) ?></strong><span>Jg. <?= (int)$runner['birth_year'] ?> · Quali <?= e(TimeParser::format((int)$runner['best_qualification_time_tenths'])) ?></span></div>
                                <label>Finalzeit<input name="time" inputmode="decimal" autocomplete="off" placeholder="z. B. 83.4" value="<?= e(TimeParser::format($runner['final_time_tenths'] !== null ? (int)$runner['final_time_tenths'] : null)) ?>"></label>
                                <div class="mobile-final-actions"><button type="submit" name="action" value="save">Zeit speichern</button><button type="submit" name="action" value="present_no_run">Am Start, nicht gelaufen</button><button type="submit" name="action" value="absent" class="danger">Nicht erschienen</button></div>
                                <?php if ($runner['final_status'] === 'present_no_run'): ?><div class="bad">Automatisch Rang 3</div><?php elseif ($runner['final_status'] === 'absent'): ?><div class="bad">Nicht erschienen: Platzverlust</div><?php endif; ?>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div><?php
        });
        return;
    }

    if ($path === '/final-results' && $method === 'GET') {
        render('Finalzeiten erfassen', function (): void {
            $event = requireEvent();
            $stmt = db()->prepare(
                'SELECT p.*, c.name AS category_name, r.final_time_tenths, r.final_status
                 FROM participants p JOIN categories c ON c.id = p.category_id JOIN results r ON r.participant_id = p.id
                 WHERE p.event_id = :event_id AND r.finalist_confirmed = 1
                 ORDER BY c.sort_order, p.gender, p.last_name, p.first_name'
            );
            $stmt->execute(['event_id' => $event['id']]);
            $mobileUrl = absoluteUrl('/mobile-final-results?event_id=' . (int)$event['id']);
            ?><section class="final-qr panel"><div><h2>Mobile Finalerfassung</h2><p>QR-Code mit dem Smartphone scannen und Finalzeiten direkt mobil erfassen.</p><a href="<?= e($mobileUrl) ?>"><?= e($mobileUrl) ?></a></div><a href="<?= e($mobileUrl) ?>"><img src="/mobile-final-results/qr?event_id=<?= (int)$event['id'] ?>" alt="QR-Code zur mobilen Finalerfassung" width="220" height="220"></a></section><?php
            ?><form method="post" action="/final-results/save"><table><thead><tr><th>Gruppe</th><th>Name</th><th>Vorname</th><th>Finalzeit</th><th>Status</th></tr></thead><tbody><?php
            foreach ($stmt as $row) {
                ?><tr>
                    <td><?= e($row['category_name']) ?> <?= e($row['gender'] === 'female' ? 'Maedchen' : 'Knaben') ?></td>
                    <td><?= e($row['last_name']) ?></td>
                    <td><?= e($row['first_name']) ?></td>
                    <td><input name="final[<?= (int)$row['id'] ?>][time]" value="<?= e(TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null)) ?>"></td>
                    <td><select name="final[<?= (int)$row['id'] ?>][status]">
                        <?php foreach (['qualified' => 'qualifiziert', 'valid' => 'gelaufen', 'present_no_run' => 'am Start, nicht gelaufen (Rang 3)', 'absent' => 'nicht erschienen (Platzverlust)', 'dnf' => 'nicht im Ziel', 'dsq' => 'disqualifiziert'] as $status => $label): ?>
                            <option value="<?= e($status) ?>" <?= $row['final_status'] === $status ? 'selected' : '' ?>><?= e($label) ?></option>
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
            $groups = (new RankingService(db()))->finalRows((int)$event['id']);
            ?><div class="toolbar"><a class="button light" href="/rankings/pdf?type=final">Druck/PDF</a><a class="button light" href="/export/csv">CSV</a></div><?php
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, true);
            }
        });
        return;
    }

    if ($path === '/rankings/pdf' && $method === 'GET') {
        $event = requireEvent();
        $type = $_GET['type'] ?? 'final';
        $service = new RankingService(db());
        $groups = $type === 'qualification' ? $service->qualificationRows((int)$event['id']) : $service->finalRows((int)$event['id']);
        $html = printablePage($type === 'qualification' ? 'Qualifikationsrangliste' : 'Endrangliste', function () use ($groups, $type): void {
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, $type !== 'qualification');
            }
        });
        PdfService::output($html, 'rangliste.pdf');
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
        PdfService::output($html, 'laufzettel.pdf', 'landscape');
        return;
    }

    if ($path === '/export/csv' && $method === 'GET') {
        $event = requireEvent();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="endrangliste.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Rang', 'Name', 'Vorname', 'Jahrgang', 'Geschlecht', 'Klasse', 'Ort', 'Kategorie', 'Lauf 1', 'Lauf 2', 'Beste Qualifikation', 'Finalist', 'Finalzeit', 'Wertungsstatus'], ';');
        foreach ((new RankingService(db()))->finalRows((int)$event['id']) as $group => $rows) {
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['rank'], $row['last_name'], $row['first_name'], $row['birth_year'],
                    $row['gender'] === 'female' ? 'Maedchen' : 'Knabe', $row['school_class'], $row['city'],
                    $row['category_name'], TimeParser::format($row['run1_time_tenths'] !== null ? (int)$row['run1_time_tenths'] : null),
                    TimeParser::format($row['run2_time_tenths'] !== null ? (int)$row['run2_time_tenths'] : null),
                    TimeParser::format((int)$row['best_qualification_time_tenths']),
                    (int)$row['finalist_confirmed'] === 1 ? 'ja' : 'nein',
                    TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null),
                    $row['ranking_segment'] ?? $row['qualification_status'],
                ], ';');
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
