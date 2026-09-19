-- Reference schema if you set $USE_SQLITE = false in config.php.
-- The app also creates these automatically on first run, this file is just for reference/manual setup.

CREATE DATABASE IF NOT EXISTS office_tracker CHARACTER SET utf8mb4;
USE office_tracker;

CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(50) NOT NULL,
    room_type VARCHAR(5) NOT NULL,      -- DO, SO, M, F, T
    capacity INT NULL,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE desks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    seat_index INT NOT NULL DEFAULT 0,  -- 0 or 1 within a room
    name VARCHAR(150) DEFAULT '',
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE desk_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    desk_id INT NOT NULL,
    date DATE NOT NULL,
    period VARCHAR(2) NOT NULL,         -- 'am' or 'pm'
    text VARCHAR(100) DEFAULT '',
    color VARCHAR(10) DEFAULT 'none',   -- none / green / red
    UNIQUE KEY uniq_desk_date_period (desk_id, date, period),
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    date DATE NOT NULL,
    hour TINYINT NOT NULL,              -- 9..16 (slot starting at that hour)
    text VARCHAR(100) DEFAULT '',
    UNIQUE KEY uniq_room_date_hour (room_id, date, hour),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;
