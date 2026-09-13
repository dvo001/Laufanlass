-- Einmalig auf bestehenden Installationen ausfuehren, waehrend die App offline ist.
-- Zuerst Datenbank sichern. Neuinstallationen verwenden direkt schema.sql.
ALTER TABLE results
    ADD COLUMN run1_time_hundredths INT NULL,
    ADD COLUMN run2_time_hundredths INT NULL,
    ADD COLUMN best_qualification_time_hundredths INT NULL,
    ADD COLUMN final_time_hundredths INT NULL;

-- Bestehende Zeiten behalten ihren Wert in Sekunden; NULL bleibt NULL.
UPDATE results SET
    run1_time_hundredths = run1_time_tenths * 10,
    run2_time_hundredths = run2_time_tenths * 10,
    best_qualification_time_hundredths = best_qualification_time_tenths * 10,
    final_time_hundredths = final_time_tenths * 10;

ALTER TABLE results
    DROP INDEX idx_results_qualification_time,
    DROP INDEX idx_results_final_time,
    DROP COLUMN run1_time_tenths,
    DROP COLUMN run2_time_tenths,
    DROP COLUMN best_qualification_time_tenths,
    DROP COLUMN final_time_tenths,
    ADD INDEX idx_results_qualification_time (best_qualification_time_hundredths),
    ADD INDEX idx_results_final_time (final_time_hundredths);
