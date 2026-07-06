-- Einmalig auf bestehenden Installationen ausfuehren.
-- Neue Installationen enthalten diese Spalten bereits in database/schema.sql.
ALTER TABLE events
    ADD COLUMN qualification_runs TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER time_window,
    ADD COLUMN final_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER qualification_runs,
    ADD COLUMN finalists_per_group TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER final_enabled,
    ADD COLUMN logo_path VARCHAR(255) NULL AFTER finalists_per_group;

-- Bisher verwendetes Logo fuer bereits vorhandene Anlaesse beibehalten.
UPDATE events
SET logo_path = '/assets/img/laufblatt-logo.png'
WHERE logo_path IS NULL;
