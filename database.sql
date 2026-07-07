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
    name VARCHAR(255) NOT NULL,
    barangay VARCHAR(255) NOT NULL,
    contact_number VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_event_number (event_id, number),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prizes Table
CREATE TABLE IF NOT EXISTS prizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL DEFAULT 1,
    prize_name VARCHAR(255) NOT NULL,
    image_path VARCHAR(255),
    quantity INT NOT NULL DEFAULT 1,
    original_quantity INT NOT NULL DEFAULT 1,
    type ENUM('Major', 'Minor') NOT NULL,
    status ENUM('Active', 'Disabled') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Winners Table
CREATE TABLE IF NOT EXISTS winners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL DEFAULT 1,
    participant_id INT NOT NULL,
    prize_id INT NOT NULL,
    number VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    barangay VARCHAR(255) NOT NULL,
    prize_name VARCHAR(255) NOT NULL,
    prize_type ENUM('Major', 'Minor') NOT NULL,
    won_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    FOREIGN KEY (prize_id) REFERENCES prizes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create indexes for better performance
CREATE INDEX idx_number ON participants(number);
CREATE INDEX idx_prize_type ON prizes(type);
CREATE INDEX idx_winner_number ON winners(number);
CREATE INDEX idx_event_participants ON participants(event_id);
CREATE INDEX idx_event_prizes ON prizes(event_id);
CREATE INDEX idx_event_winners ON winners(event_id);
