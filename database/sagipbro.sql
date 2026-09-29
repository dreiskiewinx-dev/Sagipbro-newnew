CREATE DATABASE IF NOT EXISTS sagipbro_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sagipbro_db;

CREATE TABLE IF NOT EXISTS users (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	full_name VARCHAR(150) NOT NULL,
	username VARCHAR(80) NOT NULL UNIQUE,
	email VARCHAR(160) NULL,
	position VARCHAR(150) NULL,
	contact VARCHAR(30) NULL,
	language VARCHAR(20) NOT NULL DEFAULT 'English',
	notify_stock TINYINT(1) NOT NULL DEFAULT 1,
	notify_centers TINYINT(1) NOT NULL DEFAULT 1,
	notify_digest TINYINT(1) NOT NULL DEFAULT 0,
	password_hash VARCHAR(255) NOT NULL,
	role ENUM('admin', 'official', 'volunteer', 'resident') NOT NULL DEFAULT 'resident',
	status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
	force_password_change TINYINT(1) NOT NULL DEFAULT 0,
	last_login_at DATETIME NULL,
	password_changed_at DATETIME NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS households (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	household_no VARCHAR(40) NOT NULL UNIQUE,
	address VARCHAR(255) NOT NULL,
	barangay VARCHAR(100) NOT NULL,
	head_resident_id INT UNSIGNED NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS residents (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	household_id INT UNSIGNED NULL,
	first_name VARCHAR(80) NOT NULL,
	last_name VARCHAR(80) NOT NULL,
	sex ENUM('Male', 'Female', 'Other') NOT NULL,
	birth_date DATE NULL,
	contact_no VARCHAR(30) NULL,
	address VARCHAR(255) NULL,
	vulnerability VARCHAR(150) NULL,
	status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT fk_resident_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE households ADD CONSTRAINT fk_household_head FOREIGN KEY (head_resident_id) REFERENCES residents(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS resources (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(120) NOT NULL,
	category VARCHAR(80) NOT NULL,
	unit VARCHAR(30) NOT NULL,
	stock INT UNSIGNED NOT NULL DEFAULT 0,
	low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 10,
	location VARCHAR(255) NULL,
	notes TEXT NULL,
	status ENUM('Available', 'Inactive') NOT NULL DEFAULT 'Available',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evacuation_centers (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(150) NOT NULL,
	address VARCHAR(255) NOT NULL,
	capacity INT UNSIGNED NOT NULL,
	occupants INT UNSIGNED NOT NULL DEFAULT 0,
	contact_person VARCHAR(150) NULL,
	contact_number VARCHAR(30) NULL,
	notes TEXT NULL,
	status ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evacuees (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	resident_id INT UNSIGNED NULL,
	center_id INT UNSIGNED NOT NULL,
	name VARCHAR(150) NOT NULL,
	contact_no VARCHAR(30) NULL,
	checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	checked_out_at DATETIME NULL,
	FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE SET NULL,
	FOREIGN KEY (center_id) REFERENCES evacuation_centers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS distributions (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	resource_id INT UNSIGNED NOT NULL,
	recipient_resident_id INT UNSIGNED NULL,
	recipient_reference VARCHAR(80) NULL,
	recipient_name VARCHAR(150) NOT NULL,
	quantity INT UNSIGNED NOT NULL,
	distributed_by INT UNSIGNED NOT NULL,
	distributed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	location VARCHAR(255) NULL,
	remarks TEXT NULL,
	status ENUM('Completed', 'Pending review') NOT NULL DEFAULT 'Completed',
	FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE RESTRICT,
	FOREIGN KEY (recipient_resident_id) REFERENCES residents(id) ON DELETE SET NULL,
	FOREIGN KEY (distributed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	title VARCHAR(180) NOT NULL,
	body TEXT NOT NULL,
	category VARCHAR(100) NOT NULL DEFAULT 'Advisory',
	audience VARCHAR(100) NOT NULL DEFAULT 'All residents',
	status ENUM('Draft', 'Published', 'Archived') NOT NULL DEFAULT 'Draft',
	created_by INT UNSIGNED NOT NULL,
	published_at DATETIME NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	user_id INT UNSIGNED NULL,
	action VARCHAR(80) NOT NULL,
	entity_type VARCHAR(80) NOT NULL,
	entity_id BIGINT UNSIGNED NULL,
	details JSON NULL,
	ip_address VARCHAR(45) NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_notification_state (
	user_id INT UNSIGNED PRIMARY KEY,
	last_seen_at DATETIME NULL,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT fk_notification_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_notification_reads (
	user_id INT UNSIGNED NOT NULL,
	notification_id VARCHAR(191) NOT NULL,
	read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (user_id, notification_id),
	CONSTRAINT fk_notification_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_sessions (
	session_hash CHAR(64) PRIMARY KEY,
	user_id INT UNSIGNED NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	last_seen_at DATETIME NOT NULL,
	signed_out_at DATETIME NULL,
	INDEX idx_user_sessions_presence (user_id, signed_out_at, last_seen_at),
	CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS volunteers (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	full_name VARCHAR(150) NOT NULL,
	contact VARCHAR(30) NOT NULL,
	email VARCHAR(150) NULL,
	availability VARCHAR(80) NOT NULL,
	skills VARCHAR(255) NOT NULL,
	assignment VARCHAR(120) NULL,
	notes TEXT NULL,
	status ENUM('Active', 'Deployed', 'Inactive') NOT NULL DEFAULT 'Active',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(120) NOT NULL,
	email VARCHAR(160) NOT NULL,
	phone VARCHAR(30) NULL,
	sitio VARCHAR(100) NULL,
	subject VARCHAR(80) NOT NULL,
	message TEXT NOT NULL,
	status ENUM('Unread', 'Read', 'Resolved') NOT NULL DEFAULT 'Unread',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_residents_household ON residents(household_id);
CREATE INDEX idx_evacuees_center_active ON evacuees(center_id, checked_out_at);
CREATE INDEX idx_logs_created ON activity_logs(created_at);

-- Public distribution schedules are separate from private recipient transactions.
CREATE TABLE IF NOT EXISTS distribution_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    location VARCHAR(255) NOT NULL,
    details TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    status ENUM('Upcoming', 'Active', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Upcoming',
    publication_status ENUM('Draft', 'Published') NOT NULL DEFAULT 'Draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_public_event_schedule (publication_status, status, starts_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS distribution_event_resources (
    event_id INT UNSIGNED NOT NULL,
    resource_id INT UNSIGNED NOT NULL,
    planned_quantity DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (event_id, resource_id),
    CONSTRAINT fk_public_event_plan FOREIGN KEY (event_id) REFERENCES distribution_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_public_event_resource FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE distributions ADD COLUMN event_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_distribution_public_event FOREIGN KEY (event_id) REFERENCES distribution_events(id) ON DELETE SET NULL;
ALTER TABLE announcements ADD COLUMN priority ENUM('Normal', 'Urgent') NOT NULL DEFAULT 'Normal',
    ADD COLUMN expires_at DATETIME NULL;
