INSERT INTO events (name, event_date, distance_label, time_window, status)
VALUES ('Sportlauf', CURDATE(), 'Kurzstrecke', 'Vormittag', 'active');

SET @event_id = LAST_INSERT_ID();

INSERT INTO categories (event_id, name, year_from, year_to, sort_order) VALUES
(@event_id, 'Kat. 1', 2019, 2020, 10),
(@event_id, 'Kat. 2', 2017, 2018, 20),
(@event_id, 'Kat. 3', 2015, 2016, 30);
