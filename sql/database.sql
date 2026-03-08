CREATE DATABASE IF NOT EXISTS sports_event_db;
USE sports_event_db;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','student') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    registration_id VARCHAR(50) NULL,
    email VARCHAR(100) NULL,
    phone VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    reg_start_date DATE NOT NULL,
    reg_end_date DATE NOT NULL,
    event_start_date DATE NOT NULL,
    event_end_date DATE NOT NULL,
    event_start_time TIME NOT NULL,
    event_end_time TIME NOT NULL,
    status ENUM('draft','registration','ongoing','completed') DEFAULT 'draft',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE event_sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sport_name VARCHAR(50) NOT NULL,
    bracket_type ENUM('single_elim_one','single_elim_two','double_elim','round_robin') NOT NULL DEFAULT 'single_elim_one',
    format ENUM('single_stage','double_stage') DEFAULT 'single_stage',
    second_stage_bracket VARCHAR(50) NULL,
    avg_game_time INT DEFAULT 30,
    placement_type ENUM('random','manual') DEFAULT 'random',
    is_team_sport TINYINT(1) DEFAULT 0,
    max_teams INT NULL,
    members_per_team INT NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE TABLE registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    player_name VARCHAR(100) NOT NULL,
    reg_id VARCHAR(50) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    gender ENUM('male','female','other') NOT NULL,
    sport1_id INT NOT NULL,
    sport2_id INT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (sport1_id) REFERENCES event_sports(id),
    FOREIGN KEY (sport2_id) REFERENCES event_sports(id),
    UNIQUE KEY unique_reg (event_id, user_id)
);

CREATE TABLE teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_sport_id INT NOT NULL,
    team_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (event_sport_id) REFERENCES event_sports(id) ON DELETE CASCADE
);

CREATE TABLE team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_sport_id INT NOT NULL,
    round INT NOT NULL,
    match_number INT NOT NULL,
    player1_id INT NULL,
    player2_id INT NULL,
    player1_name VARCHAR(100) DEFAULT 'BYE',
    player2_name VARCHAR(100) DEFAULT 'BYE',
    is_player1_bye TINYINT(1) DEFAULT 0,
    is_player2_bye TINYINT(1) DEFAULT 0,
    score1 VARCHAR(50) NULL,
    score2 VARCHAR(50) NULL,
    winner_id INT NULL,
    winner_name VARCHAR(100) NULL,
    next_match_id INT NULL,
    loser_match_id INT NULL,
    scheduled_time DATETIME NULL,
    gender ENUM('male','female','other') NOT NULL DEFAULT 'male',
    status ENUM('pending','ongoing','completed','disqualified') DEFAULT 'pending',
    stage ENUM('stage1','stage2') DEFAULT 'stage1',
    bracket_position INT DEFAULT 0,
    FOREIGN KEY (event_sport_id) REFERENCES event_sports(id) ON DELETE CASCADE
);

CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_sport_id INT NOT NULL,
    position INT NOT NULL,
    player_id INT NULL,
    player_name VARCHAR(100) NOT NULL,
    team_id INT NULL,
    FOREIGN KEY (event_sport_id) REFERENCES event_sports(id) ON DELETE CASCADE
);
