INSERT INTO events (name, event_date, distance_label, time_window, status)
SELECT 'dä schnälsti Winkler', '2026-09-05', '2x300m', NULL, 'active'
WHERE NOT EXISTS (SELECT 1 FROM events);

SET @event_id := (
    SELECT id
    FROM events
    WHERE status = 'active'
    ORDER BY event_date DESC, id DESC
    LIMIT 1
);

SET @event_id := COALESCE(@event_id, (SELECT id FROM events ORDER BY id DESC LIMIT 1));

INSERT INTO categories (event_id, name, year_from, year_to, sort_order, active)
SELECT @event_id, desired.name, desired.year_from, desired.year_to, desired.sort_order, 1
FROM (
    SELECT '2022 und jünger' AS name, 2022 AS year_from, 2026 AS year_to, 10 AS sort_order UNION ALL
    SELECT '2020/2021', 2020, 2021, 20 UNION ALL
    SELECT '2018/2019', 2018, 2019, 30 UNION ALL
    SELECT '2016/2017', 2016, 2017, 40 UNION ALL
    SELECT '2014/2015', 2014, 2015, 50 UNION ALL
    SELECT '2012/2013', 2012, 2013, 60 UNION ALL
    SELECT '2010/2011', 2010, 2011, 70 UNION ALL
    SELECT '2008/2009', 2008, 2009, 80 UNION ALL
    SELECT '1995/2007', 1995, 2007, 90 UNION ALL
    SELECT '1994 und älter', 1920, 1994, 100
) AS desired
WHERE NOT EXISTS (
    SELECT 1
    FROM categories c
    WHERE c.event_id = @event_id
      AND c.name = desired.name
);

UPDATE categories c
JOIN (
    SELECT '2022 und jünger' AS name, 2022 AS year_from, 2026 AS year_to, 10 AS sort_order UNION ALL
    SELECT '2020/2021', 2020, 2021, 20 UNION ALL
    SELECT '2018/2019', 2018, 2019, 30 UNION ALL
    SELECT '2016/2017', 2016, 2017, 40 UNION ALL
    SELECT '2014/2015', 2014, 2015, 50 UNION ALL
    SELECT '2012/2013', 2012, 2013, 60 UNION ALL
    SELECT '2010/2011', 2010, 2011, 70 UNION ALL
    SELECT '2008/2009', 2008, 2009, 80 UNION ALL
    SELECT '1995/2007', 1995, 2007, 90 UNION ALL
    SELECT '1994 und älter', 1920, 1994, 100
) AS desired ON desired.name = c.name
SET c.year_from = desired.year_from,
    c.year_to = desired.year_to,
    c.sort_order = desired.sort_order,
    c.active = 1
WHERE c.event_id = @event_id;

UPDATE categories
SET active = 0
WHERE event_id = @event_id
  AND name LIKE 'Jg. %';

INSERT INTO participants
    (event_id, category_id, sheet_number, last_name, first_name, birth_year, gender, school_class, city, notes)
SELECT
    @event_id,
    (
        SELECT c.id
        FROM categories c
        WHERE c.event_id = @event_id
          AND c.name = seed_rows.category_name
          AND c.active = 1
        ORDER BY c.id
        LIMIT 1
    ) AS category_id,
    LPAD(seed_rows.n, 3, '0') AS sheet_number,
    ELT(1 + MOD(FLOOR((seed_rows.n - 1) / 10), 10),
        'Meier', 'Mueller', 'Schmid', 'Keller', 'Weber',
        'Fischer', 'Huber', 'Steiner', 'Baumann', 'Gerber') AS last_name,
    IF(MOD(seed_rows.n, 2) = 0,
        ELT(1 + MOD(seed_rows.n - 1, 10),
            'Noah', 'Leon', 'Elias', 'Luca', 'Finn',
            'Ben', 'Tim', 'Jonas', 'David', 'Moritz'),
        ELT(1 + MOD(seed_rows.n - 1, 10),
            'Lena', 'Mia', 'Emma', 'Sofia', 'Anna',
            'Laura', 'Nina', 'Julia', 'Lea', 'Sara')
    ) AS first_name,
    seed_rows.birth_year,
    IF(MOD(seed_rows.n, 2) = 0, 'male', 'female') AS gender,
    CONCAT('Klasse ', 1 + MOD(seed_rows.n - 1, 9), CHAR(97 + MOD(seed_rows.n - 1, 3))) AS school_class,
    ELT(1 + MOD(seed_rows.n - 1, 10),
        'Winkel', 'Buelach', 'Kloten', 'Embrach', 'Bassersdorf',
        'Oberglatt', 'Niederglatt', 'Dielsdorf', 'Ruemlang', 'Opfikon') AS city,
    'Teilnehmer-Seed' AS notes
FROM (
    SELECT
        numbers.n,
        years.birth_year,
        CASE
            WHEN years.birth_year >= 2022 THEN '2022 und jünger'
            WHEN years.birth_year BETWEEN 2020 AND 2021 THEN '2020/2021'
            WHEN years.birth_year BETWEEN 2018 AND 2019 THEN '2018/2019'
            WHEN years.birth_year BETWEEN 2016 AND 2017 THEN '2016/2017'
            WHEN years.birth_year BETWEEN 2014 AND 2015 THEN '2014/2015'
            WHEN years.birth_year BETWEEN 2012 AND 2013 THEN '2012/2013'
            WHEN years.birth_year BETWEEN 2010 AND 2011 THEN '2010/2011'
            WHEN years.birth_year BETWEEN 2008 AND 2009 THEN '2008/2009'
            WHEN years.birth_year BETWEEN 1995 AND 2007 THEN '1995/2007'
            ELSE '1994 und älter'
        END AS category_name
    FROM (
        SELECT ones.i + tens.i * 10 + 1 AS n
        FROM (
            SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL
            SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
        ) AS ones
        CROSS JOIN (
            SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL
            SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
        ) AS tens
    ) AS numbers
    JOIN (
        SELECT 1 AS n, 1988 AS birth_year UNION ALL
        SELECT 2, 1991 UNION ALL
        SELECT 3, 1994 UNION ALL
        SELECT 4, 1995 UNION ALL
        SELECT 5, 1998 UNION ALL
        SELECT 6, 2001 UNION ALL
        SELECT 7, 2004 UNION ALL
        SELECT 8, 2007 UNION ALL
        SELECT 9, 2008 UNION ALL
        SELECT 10, 2009 UNION ALL
        SELECT 11, 2010 UNION ALL
        SELECT 12, 2011 UNION ALL
        SELECT 13, 2012 UNION ALL
        SELECT 14, 2013 UNION ALL
        SELECT 15, 2014 UNION ALL
        SELECT 16, 2015 UNION ALL
        SELECT 17, 2016 UNION ALL
        SELECT 18, 2017 UNION ALL
        SELECT 19, 2018 UNION ALL
        SELECT 20, 2019 UNION ALL
        SELECT 21, 2020 UNION ALL
        SELECT 22, 2021 UNION ALL
        SELECT 23, 2022 UNION ALL
        SELECT 24, 2023 UNION ALL
        SELECT 25, 2024 UNION ALL
        SELECT 26, 2025 UNION ALL
        SELECT 27, 2026 UNION ALL
        SELECT 28, 2003 UNION ALL
        SELECT 29, 2006 UNION ALL
        SELECT 30, 2016
    ) AS years ON years.n = 1 + MOD(numbers.n - 1, 30)
    WHERE numbers.n <= 100
) AS seed_rows
ORDER BY seed_rows.n
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    last_name = VALUES(last_name),
    first_name = VALUES(first_name),
    birth_year = VALUES(birth_year),
    gender = VALUES(gender),
    school_class = VALUES(school_class),
    city = VALUES(city),
    notes = VALUES(notes);

INSERT INTO results (participant_id)
SELECT p.id
FROM participants p
LEFT JOIN results r ON r.participant_id = p.id
WHERE p.event_id = @event_id
  AND r.id IS NULL;

UPDATE results r
JOIN participants p ON p.id = r.participant_id
JOIN (
    SELECT
        numbers.n,
        120 + MOD(numbers.n * 17 + 23, 121) AS run1_time_tenths,
        120 + MOD(numbers.n * 19 + 41, 121) AS run2_time_tenths
    FROM (
        SELECT ones.i + tens.i * 10 + 1 AS n
        FROM (
            SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL
            SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
        ) AS ones
        CROSS JOIN (
            SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL
            SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
        ) AS tens
    ) AS numbers
    WHERE numbers.n <= 100
) AS seed_times ON seed_times.n = CAST(p.sheet_number AS UNSIGNED)
SET r.run1_time_tenths = seed_times.run1_time_tenths,
    r.run2_time_tenths = seed_times.run2_time_tenths,
    r.best_qualification_time_tenths = LEAST(seed_times.run1_time_tenths, seed_times.run2_time_tenths),
    r.qualification_status = 'valid'
WHERE p.event_id = @event_id
  AND p.notes = 'Teilnehmer-Seed';
