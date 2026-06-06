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
    CONSTRAINT fk_categories_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
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
    CONSTRAINT fk_participants_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
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
    CONSTRAINT fk_results_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_results_participant (participant_id),
    INDEX idx_results_qualification_time (best_qualification_time_tenths),
    INDEX idx_results_final_time (final_time_tenths),
    INDEX idx_results_finalist (is_finalist),
    INDEX idx_results_finalist_confirmed (finalist_confirmed)
);
