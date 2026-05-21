SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS leader_schedules;
DROP TABLE IF EXISTS team_locations;
DROP TABLE IF EXISTS posts;
DROP TABLE IF EXISTS leaders;
DROP TABLE IF EXISTS teams;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    parent_token VARCHAR(128) NOT NULL UNIQUE,
    description TEXT NULL,
    status ENUM('not_started','on_route','checked_in','resting','delayed','needs_follow_up','completed') NOT NULL DEFAULT 'not_started',
    current_location_name VARCHAR(255) NULL,
    current_latitude DECIMAL(10,7) NULL,
    current_longitude DECIMAL(10,7) NULL,
    last_check_in_at DATETIME NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leaders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('trip_admin','leader') NOT NULL DEFAULT 'leader',
    bio TEXT NULL,
    photo_url VARCHAR(500) NULL,
    phone VARCHAR(80) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NULL,
    leader_id INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    post_type ENUM('general','team_update','check_in','photo','important') NOT NULL DEFAULT 'general',
    visibility ENUM('public','team') NOT NULL DEFAULT 'public',
    photo_url VARCHAR(500) NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_posts_team_id (team_id),
    INDEX idx_posts_leader_id (leader_id),
    INDEX idx_posts_visibility (visibility),
    INDEX idx_posts_published_at (published_at),
    CONSTRAINT fk_posts_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_posts_leader FOREIGN KEY (leader_id) REFERENCES leaders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    leader_id INT UNSIGNED NULL,
    location_name VARCHAR(255) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    public_note TEXT NULL,
    internal_note TEXT NULL,
    checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_team_locations_team_id (team_id),
    INDEX idx_team_locations_checked_in_at (checked_in_at),
    CONSTRAINT fk_team_locations_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_locations_leader FOREIGN KEY (leader_id) REFERENCES leaders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leader_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leader_id INT UNSIGNED NOT NULL,
    schedule_start DATETIME NOT NULL,
    schedule_end DATETIME NOT NULL,
    status ENUM('in_country','home_contact') NOT NULL DEFAULT 'in_country',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leader_schedules_leader_id (leader_id),
    INDEX idx_leader_schedules_dates (schedule_start, schedule_end),
    CONSTRAINT fk_leader_schedules_leader FOREIGN KEY (leader_id) REFERENCES leaders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO leaders (name, email, password_hash, role, bio, is_active)
VALUES ('Trip Admin', 'admin@example.com', '$2y$10$wZlU7vKJgFhZHlB1E7E3WOeV5NYLReV6MnH4V5I8aNnKsWJ4mBV7G', 'trip_admin', 'Trip administrator for the Explorer Belt portal.', 1);

INSERT INTO teams (name, slug, parent_token, description, status, is_public) VALUES
('Team Kestrel', 'team-kestrel', 'team-kestrel-parent-token-2026-change-me', 'Explorer Belt team.', 'not_started', 1),
('Team Oak', 'team-oak', 'team-oak-parent-token-2026-change-me', 'Explorer Belt team.', 'not_started', 1),
('Team Swift', 'team-swift', 'team-swift-parent-token-2026-change-me', 'Explorer Belt team.', 'not_started', 1);

INSERT INTO posts (team_id, leader_id, title, body, post_type, visibility, is_published)
VALUES (NULL, 1, 'Welcome to Explorer Belt Live', 'This portal will be used to share approved updates from the Explorer Belt trip.', 'general', 'public', 1);
