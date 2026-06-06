#!/usr/bin/env bash
set -Eeuo pipefail

# ==============================================================================
# setup_sportlauf_lamp_ubuntu_26_04.sh
#
# Zweck:
#   Installiert einen passenden LAMP-Stack für die lokale Sportlauf-Webapplikation
#   auf Ubuntu Server 26.04.
#
# Enthalten:
#   - Apache 2
#   - MariaDB
#   - PHP inkl. PHP-FPM und benötigter Extensions
#   - Composer
#   - Apache VirtualHost für /var/www/sportlauf/public
#   - MariaDB-Datenbank und Benutzer
#   - lokale Testseiten
#
# Verwendung:
#   chmod +x setup_sportlauf_lamp_ubuntu_26_04.sh
#   sudo ./setup_sportlauf_lamp_ubuntu_26_04.sh
#
# Optional mit Parametern:
#   sudo APP_DOMAIN=sportlauf.local \
#        APP_DIR=/var/www/sportlauf \
#        DB_NAME=sportlauf \
#        DB_USER=sportlauf_user \
#        DB_PASS='EinStarkesPasswort' \
#        ./setup_sportlauf_lamp_ubuntu_26_04.sh
#
# Hinweis:
#   Wenn DB_PASS nicht gesetzt ist, wird ein zufälliges Passwort erzeugt und in
#   /root/sportlauf_db_credentials.txt gespeichert.
# ==============================================================================

APP_NAME="${APP_NAME:-sportlauf}"
APP_DOMAIN="${APP_DOMAIN:-sportlauf.local}"
APP_DIR="${APP_DIR:-/var/www/sportlauf}"
APP_PUBLIC_DIR="${APP_DIR}/public"
APP_STORAGE_DIR="${APP_DIR}/storage"
DB_NAME="${DB_NAME:-sportlauf}"
DB_USER="${DB_USER:-sportlauf_user}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-localhost}"
APACHE_CONF="/etc/apache2/sites-available/${APP_NAME}.conf"
CREDENTIAL_FILE="/root/${APP_NAME}_db_credentials.txt"
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-256M}"
PHP_UPLOAD_MAX_FILESIZE="${PHP_UPLOAD_MAX_FILESIZE:-32M}"
PHP_POST_MAX_SIZE="${PHP_POST_MAX_SIZE:-32M}"
PHP_MAX_EXECUTION_TIME="${PHP_MAX_EXECUTION_TIME:-60}"

log() {
    echo -e "\033[1;32m[OK]\033[0m $*"
}

info() {
    echo -e "\033[1;34m[INFO]\033[0m $*"
}

warn() {
    echo -e "\033[1;33m[WARN]\033[0m $*"
}

fail() {
    echo -e "\033[1;31m[ERROR]\033[0m $*" >&2
    exit 1
}

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        fail "Bitte mit sudo/root ausführen."
    fi
}

check_ubuntu_version() {
    if [[ ! -f /etc/os-release ]]; then
        fail "/etc/os-release nicht gefunden. Dieses Skript ist für Ubuntu Server gedacht."
    fi

    # shellcheck disable=SC1091
    source /etc/os-release

    if [[ "${ID:-}" != "ubuntu" ]]; then
        fail "Dieses Skript ist für Ubuntu gedacht. Erkannt: ${ID:-unbekannt}"
    fi

    if [[ "${VERSION_ID:-}" != "26.04" ]]; then
        warn "Erwartet wird Ubuntu 26.04. Erkannt: ${VERSION_ID:-unbekannt}. Fortsetzung trotzdem möglich."
    else
        log "Ubuntu ${VERSION_ID} erkannt."
    fi
}

generate_db_password_if_needed() {
    if [[ -z "${DB_PASS}" ]]; then
        DB_PASS="$(openssl rand -base64 32 | tr -d '\n')"
        umask 077
        cat > "${CREDENTIAL_FILE}" <<EOF
Datenbankzugang für ${APP_NAME}

DB_HOST=${DB_HOST}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF
        log "DB_PASS wurde automatisch erzeugt und gespeichert unter: ${CREDENTIAL_FILE}"
    else
        log "DB_PASS wurde über Umgebungsvariable gesetzt."
    fi
}

install_packages() {
    info "Paketlisten aktualisieren und Systempakete installieren..."
    apt update
    DEBIAN_FRONTEND=noninteractive apt upgrade -y

    DEBIAN_FRONTEND=noninteractive apt install -y \
        apache2 \
        mariadb-server \
        mariadb-client \
        php \
        php-fpm \
        php-cli \
        php-mysql \
        php-mbstring \
        php-xml \
        php-curl \
        php-zip \
        php-gd \
        php-intl \
        php-bcmath \
        php-soap \
        php-readline \
        php-opcache \
        unzip \
        curl \
        git \
        acl \
        openssl \
        composer

    log "Pakete installiert."
}

detect_php_version() {
    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    if [[ -z "${PHP_VERSION}" ]]; then
        fail "PHP-Version konnte nicht ermittelt werden."
    fi
    PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
    PHP_INI_FPM="/etc/php/${PHP_VERSION}/fpm/php.ini"
    PHP_INI_CLI="/etc/php/${PHP_VERSION}/cli/php.ini"
    log "PHP ${PHP_VERSION} erkannt."
}

configure_php() {
    info "PHP-Konfiguration anpassen..."

    for ini in "${PHP_INI_FPM}" "${PHP_INI_CLI}"; do
        if [[ -f "${ini}" ]]; then
            sed -i "s/^memory_limit = .*/memory_limit = ${PHP_MEMORY_LIMIT}/" "${ini}"
            sed -i "s/^upload_max_filesize = .*/upload_max_filesize = ${PHP_UPLOAD_MAX_FILESIZE}/" "${ini}"
            sed -i "s/^post_max_size = .*/post_max_size = ${PHP_POST_MAX_SIZE}/" "${ini}"
            sed -i "s/^max_execution_time = .*/max_execution_time = ${PHP_MAX_EXECUTION_TIME}/" "${ini}"
            sed -i "s/^;date.timezone =.*/date.timezone = Europe\/Zurich/" "${ini}" || true

            if ! grep -q "^date.timezone = Europe/Zurich" "${ini}"; then
                echo "date.timezone = Europe/Zurich" >> "${ini}"
            fi
        fi
    done

    systemctl enable "${PHP_FPM_SERVICE}"
    systemctl restart "${PHP_FPM_SERVICE}"
    log "PHP-FPM konfiguriert."
}

configure_apache() {
    info "Apache konfigurieren..."

    a2enmod rewrite headers proxy_fcgi setenvif >/dev/null

    if [[ -f "/etc/apache2/conf-available/php${PHP_VERSION}-fpm.conf" ]]; then
        a2enconf "php${PHP_VERSION}-fpm" >/dev/null
    else
        warn "php${PHP_VERSION}-fpm Apache-Konfiguration nicht gefunden. PHP-FPM wird trotzdem installiert."
    fi

    mkdir -p "${APP_PUBLIC_DIR}" "${APP_STORAGE_DIR}/logs" "${APP_STORAGE_DIR}/exports"
    chown -R www-data:www-data "${APP_DIR}"
    chmod -R 775 "${APP_DIR}"

    cat > "${APACHE_CONF}" <<EOF
<VirtualHost *:80>
    ServerName ${APP_DOMAIN}
    ServerAlias ${APP_NAME}.local
    DocumentRoot ${APP_PUBLIC_DIR}

    <Directory ${APP_PUBLIC_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Directory ${APP_DIR}>
        Require all denied
    </Directory>

    <Directory ${APP_PUBLIC_DIR}>
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${APP_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${APP_NAME}_access.log combined
</VirtualHost>
EOF

    a2ensite "${APP_NAME}.conf" >/dev/null

    if [[ -f /etc/apache2/sites-enabled/000-default.conf ]]; then
        a2dissite 000-default.conf >/dev/null || true
    fi

    apache2ctl configtest
    systemctl enable apache2
    systemctl restart apache2
    log "Apache VirtualHost aktiviert: ${APP_DOMAIN}"
}

configure_mariadb() {
    info "MariaDB starten und Datenbank einrichten..."

    systemctl enable mariadb
    systemctl start mariadb

    mysql --protocol=socket -uroot <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost'
  IDENTIFIED BY '${DB_PASS}';

ALTER USER '${DB_USER}'@'localhost'
  IDENTIFIED BY '${DB_PASS}';

GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';

FLUSH PRIVILEGES;
EOF

    log "MariaDB-Datenbank '${DB_NAME}' und Benutzer '${DB_USER}' eingerichtet."
}

create_project_skeleton() {
    info "Minimale Projektstruktur und Testseiten erstellen..."

    mkdir -p \
        "${APP_PUBLIC_DIR}/assets/css" \
        "${APP_PUBLIC_DIR}/assets/js" \
        "${APP_DIR}/app/Controllers" \
        "${APP_DIR}/app/Models" \
        "${APP_DIR}/app/Services" \
        "${APP_DIR}/app/Views" \
        "${APP_DIR}/app/Helpers" \
        "${APP_DIR}/config" \
        "${APP_DIR}/database" \
        "${APP_STORAGE_DIR}/exports" \
        "${APP_STORAGE_DIR}/logs"

    cat > "${APP_DIR}/config/database.php" <<EOF
<?php
return [
    'host' => '${DB_HOST}',
    'database' => '${DB_NAME}',
    'username' => '${DB_USER}',
    'password' => '${DB_PASS}',
    'charset' => 'utf8mb4',
];
EOF

    cat > "${APP_PUBLIC_DIR}/index.php" <<'EOF'
<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/database.php';

$phpVersion = PHP_VERSION;
$dbOk = false;
$dbError = null;

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $dbOk = true;
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Sportlauf LAMP-Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 2rem;
            line-height: 1.5;
            background: #f6f7f9;
            color: #1f2937;
        }
        main {
            max-width: 900px;
            margin: auto;
            background: #fff;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
        }
        .ok { color: #047857; font-weight: 700; }
        .fail { color: #b91c1c; font-weight: 700; }
        code {
            background: #eef2f7;
            padding: .2rem .35rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<main>
    <h1>Sportlauf LAMP-Test</h1>
    <p>Apache und PHP laufen.</p>

    <h2>Status</h2>
    <p>PHP-Version: <code><?= e($phpVersion) ?></code></p>

    <?php if ($dbOk): ?>
        <p class="ok">MariaDB-Verbindung erfolgreich.</p>
    <?php else: ?>
        <p class="fail">MariaDB-Verbindung fehlgeschlagen.</p>
        <pre><?= e((string)$dbError) ?></pre>
    <?php endif; ?>

    <h2>Nächster Schritt</h2>
    <p>Diese Testseite kann später durch die Sportlauf-Applikation ersetzt werden.</p>
</main>
</body>
</html>
EOF

    cat > "${APP_PUBLIC_DIR}/.htaccess" <<'EOF'
RewriteEngine On

# Späterer Front-Controller:
# RewriteCond %{REQUEST_FILENAME} !-f
# RewriteCond %{REQUEST_FILENAME} !-d
# RewriteRule ^ index.php [QSA,L]
EOF

    cat > "${APP_DIR}/database/schema.sql" <<'EOF'
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    distance_label VARCHAR(50) NOT NULL,
    time_window VARCHAR(100) NULL,
    status ENUM('preparation', 'active', 'closed', 'archived') NOT NULL DEFAULT 'preparation',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    year_from INT NOT NULL,
    year_to INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_categories_event
        FOREIGN KEY (event_id)
        REFERENCES events(id)
        ON DELETE CASCADE,

    INDEX idx_categories_event_years (event_id, year_from, year_to)
);

CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category_id INT NULL,
    sheet_number VARCHAR(20) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    birth_year INT NOT NULL,
    gender ENUM('female', 'male') NOT NULL,
    school_class VARCHAR(50) NULL,
    city VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_participants_event
        FOREIGN KEY (event_id)
        REFERENCES events(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_participants_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE SET NULL,

    UNIQUE KEY uq_participants_event_sheet (event_id, sheet_number),
    INDEX idx_participants_name (event_id, last_name, first_name),
    INDEX idx_participants_category_gender (event_id, category_id, gender)
);

CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    run1_time_tenths INT NULL,
    run2_time_tenths INT NULL,
    best_qualification_time_tenths INT NULL,

    is_finalist TINYINT(1) NOT NULL DEFAULT 0,
    finalist_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    final_time_tenths INT NULL,

    qualification_status ENUM('no_time', 'valid', 'dns', 'dnf', 'dsq') NOT NULL DEFAULT 'no_time',
    final_status ENUM('not_qualified', 'qualified', 'valid', 'dns', 'dnf', 'dsq') NOT NULL DEFAULT 'not_qualified',

    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_results_participant
        FOREIGN KEY (participant_id)
        REFERENCES participants(id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_results_participant (participant_id),
    INDEX idx_results_qualification_time (best_qualification_time_tenths),
    INDEX idx_results_final_time (final_time_tenths),
    INDEX idx_results_finalist (is_finalist),
    INDEX idx_results_finalist_confirmed (finalist_confirmed)
);
EOF

    cat > "${APP_DIR}/README.md" <<EOF
# Sportlauf

Lokale LAMP-Webapplikation für einen Kinder-Laufanlass.

## Installation

Dieses Verzeichnis wurde durch \`setup_sportlauf_lamp_ubuntu_26_04.sh\` vorbereitet.

## Datenbank

- Host: ${DB_HOST}
- DB: ${DB_NAME}
- User: ${DB_USER}

Das Passwort steht in:

\`${CREDENTIAL_FILE}\`

wenn es automatisch erzeugt wurde.

## Schema importieren

\`\`\`bash
mysql -u ${DB_USER} -p ${DB_NAME} < database/schema.sql
\`\`\`
EOF

    chown -R www-data:www-data "${APP_DIR}"
    find "${APP_DIR}" -type d -exec chmod 775 {} \;
    find "${APP_DIR}" -type f -exec chmod 664 {} \;

    log "Projektstruktur erstellt."
}

import_schema() {
    info "Datenbankschema importieren..."
    mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${APP_DIR}/database/schema.sql"
    log "Datenbankschema importiert."
}

configure_firewall_if_available() {
    if command -v ufw >/dev/null 2>&1; then
        info "UFW gefunden. Apache-Regel hinzufügen..."
        ufw allow OpenSSH >/dev/null || true
        ufw allow "Apache" >/dev/null || true

        if ufw status | grep -q "Status: inactive"; then
            warn "UFW ist installiert, aber inaktiv. Nicht automatisch aktiviert, um SSH-Zugriff nicht zu gefährden."
        else
            log "UFW-Regeln gesetzt."
        fi
    else
        warn "UFW nicht installiert. Firewall-Konfiguration übersprungen."
    fi
}

print_summary() {
    SERVER_IP="$(hostname -I | awk '{print $1}')"

    cat <<EOF

===============================================================================
Installation abgeschlossen
===============================================================================

Projektverzeichnis:
  ${APP_DIR}

Apache VirtualHost:
  ${APACHE_CONF}

Lokaler Aufruf:
  http://${SERVER_IP}/

Falls DNS/Hosts-Datei eingerichtet ist:
  http://${APP_DOMAIN}/

Datenbank:
  Host: ${DB_HOST}
  Name: ${DB_NAME}
  User: ${DB_USER}

EOF

    if [[ -f "${CREDENTIAL_FILE}" ]]; then
        cat <<EOF
DB-Zugangsdaten:
  ${CREDENTIAL_FILE}

EOF
    fi

    cat <<EOF
Wichtige Kommandos:

  sudo systemctl status apache2
  sudo systemctl status ${PHP_FPM_SERVICE}
  sudo systemctl status mariadb

  sudo tail -f /var/log/apache2/${APP_NAME}_error.log

Hinweis:
  Für sportlauf.local auf einem Client-PC entweder DNS einrichten oder in der
  Hosts-Datei des Clients ergänzen:

  ${SERVER_IP} ${APP_DOMAIN}

===============================================================================

EOF
}

main() {
    require_root
    check_ubuntu_version
    generate_db_password_if_needed
    install_packages
    detect_php_version
    configure_php
    configure_apache
    configure_mariadb
    create_project_skeleton
    import_schema
    configure_firewall_if_available
    print_summary
}

main "$@"
