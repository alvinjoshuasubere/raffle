-- Create Database
CREATE DATABASE IF NOT EXISTS raffle_system;
USE raffle_system;

-- Users Table (for login)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin user (password: admin123)
INSERT IGNORE INTO users (username, password, display_name) 
VALUES ('admin', '$2y$10$PPbN.lOE1stOXyYPF.AI.eB0jajXGnd.hUD.xNgK7IWFjDJqdSwGq', 'Administrator');

-- Events Table
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert a default event
INSERT IGNORE INTO events (id, name, description, status) 
VALUES (1, 'Mayors Night', 'Main raffle event', 'Active');

-- Participants Table
CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL DEFAULT 1,
    number VARCHAR(50) NOT NULL,
    lastname VARCHAR(255) NOT NULL DEFAULT '',
    firstname VARCHAR(255) NOT NULL DEFAULT '',
    middlename VARCHAR(255) NOT NULL DEFAULT '',
    suffix VARCHAR(20) NOT NULL DEFAULT '',
    name VARCHAR(255) NOT NULL,
    birthdate DATE DEFAULT NULL,
    sex VARCHAR(10) NOT NULL DEFAULT '',
    nationality VARCHAR(50) NOT NULL DEFAULT 'FILIPINO',
    province VARCHAR(255) NOT NULL DEFAULT 'South Cotabato',
    city VARCHAR(255) NOT NULL DEFAULT 'Koronadal',
    barangay VARCHAR(255) NOT NULL,
    purok VARCHAR(255) NOT NULL DEFAULT '',
    contact_number VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    dp_consent TINYINT(1) NOT NULL DEFAULT 0,
    receive_updates TINYINT(1) NOT NULL DEFAULT 0,
    parental_consent TINYINT(1) NOT NULL DEFAULT 0,
    photo_data LONGBLOB DEFAULT NULL,
    registration_attachment LONGBLOB DEFAULT NULL,
    consent_form LONGBLOB DEFAULT NULL,
    status VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_event_number (event_id, number),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Winners Table
CREATE TABLE IF NOT EXISTS winners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL DEFAULT 1,
    participant_id INT NOT NULL,
    prize_id INT DEFAULT NULL,
    number VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    barangay VARCHAR(255) NOT NULL,
    prize_name VARCHAR(255) DEFAULT '',
    prize_type VARCHAR(50) DEFAULT '',
    won_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prizes Table
CREATE TABLE IF NOT EXISTS prizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL DEFAULT 1,
    name VARCHAR(255) NOT NULL,
    image VARCHAR(500) NOT NULL DEFAULT '',
    quantity INT NOT NULL DEFAULT 1,
    claimed INT NOT NULL DEFAULT 0,
    type ENUM('Major', 'Minor') NOT NULL DEFAULT 'Minor',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create indexes for better performance
CREATE INDEX idx_number ON participants(number);
CREATE INDEX idx_winner_number ON winners(number);
CREATE INDEX idx_event_participants ON participants(event_id);
CREATE INDEX idx_event_winners ON winners(event_id);
CREATE INDEX idx_event_prizes ON prizes(event_id);

-- ============================================================
-- Migration for existing databases:
-- adds the registration wizard columns (run each statement once)
-- ============================================================
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS suffix VARCHAR(20) NOT NULL DEFAULT '' AFTER middlename;
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS sex VARCHAR(10) NOT NULL DEFAULT '' AFTER birthdate;
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS nationality VARCHAR(50) NOT NULL DEFAULT 'FILIPINO' AFTER sex;
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS email VARCHAR(255) NOT NULL DEFAULT '' AFTER contact_number;
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS dp_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER email;
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS receive_updates TINYINT(1) NOT NULL DEFAULT 0 AFTER dp_consent;
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS parental_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER receive_updates;
-- ALTER TABLE participants ADD COLUMN IF NOT EXISTS consent_form LONGBLOB NULL AFTER registration_attachment;
-- ALTER TABLE events ADD COLUMN IF NOT EXISTS registration_start_at DATETIME NULL DEFAULT NULL AFTER description;
-- ALTER TABLE events ADD COLUMN IF NOT EXISTS registration_end_at DATETIME NULL DEFAULT NULL AFTER registration_start_at;

-- ============================================================
-- Settings Table (key-value, e.g. slot timing)
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(64) PRIMARY KEY,
    svalue VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
