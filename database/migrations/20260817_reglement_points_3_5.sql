-- Reglement 2026, Punkte 3-5: Finalsteuerung, Nachruecken und Sonderwertungen.
ALTER TABLE categories
    ADD COLUMN has_final TINYINT(1) NOT NULL DEFAULT 1 AFTER active;

ALTER TABLE results
    MODIFY final_status ENUM(
        'not_qualified', 'qualified', 'valid',
        'dns', 'present_no_run', 'absent', 'dnf', 'dsq'
    ) NOT NULL DEFAULT 'not_qualified';

-- Der bisherige DNS-Status bedeutete "nicht gestartet" und wird als abwesend uebernommen.
UPDATE results SET final_status = 'absent' WHERE final_status = 'dns';

ALTER TABLE results
    MODIFY final_status ENUM(
        'not_qualified', 'qualified', 'valid',
        'present_no_run', 'absent', 'dnf', 'dsq'
    ) NOT NULL DEFAULT 'not_qualified';
