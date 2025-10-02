-- Create database
CREATE DATABASE IF NOT EXISTS raffle_system;
USE raffle_system;

-- Create participants table
CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    barangay VARCHAR(255) NOT NULL,
    isSelected TINYINT(1) DEFAULT 0,
    selected_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create winners history table (optional - for tracking multiple draws)
CREATE TABLE winners_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT,
    draw_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id)
);
