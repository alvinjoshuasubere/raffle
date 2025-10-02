-- Create Database
CREATE DATABASE IF NOT EXISTS raffle_system;
USE raffle_system;

-- Participants Table
CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    barangay VARCHAR(255) NOT NULL,
    contact_number VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prizes Table
CREATE TABLE IF NOT EXISTS prizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prize_name VARCHAR(255) NOT NULL,
    image_path VARCHAR(255),
    quantity INT NOT NULL DEFAULT 1,
    original_quantity INT NOT NULL DEFAULT 1,
    type ENUM('Major', 'Minor') NOT NULL,
    status ENUM('Active', 'Disabled') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Winners Table
CREATE TABLE IF NOT EXISTS winners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    prize_id INT NOT NULL,
    number VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    barangay VARCHAR(255) NOT NULL,
    prize_name VARCHAR(255) NOT NULL,
    prize_type ENUM('Major', 'Minor') NOT NULL,
    won_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    FOREIGN KEY (prize_id) REFERENCES prizes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create indexes for better performance
CREATE INDEX idx_number ON participants(number);
CREATE INDEX idx_prize_type ON prizes(type);
CREATE INDEX idx_winner_number ON winners(number);