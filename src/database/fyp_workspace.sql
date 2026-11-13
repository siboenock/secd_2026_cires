CREATE DATABASE IF NOT EXISTS fyp_workspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fyp_workspace;

CREATE TABLE IF NOT EXISTS accounts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 display_name VARCHAR(120) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('student','supervisor') NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projects (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(180) NOT NULL,
 student_id INT UNSIGNED NOT NULL,
 supervisor_id INT UNSIGNED NOT NULL,
 FOREIGN KEY (student_id) REFERENCES accounts(id),
 FOREIGN KEY (supervisor_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chapters (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 chapter_no SMALLINT UNSIGNED NOT NULL,
 title VARCHAR(120) NOT NULL,
 UNIQUE (project_id, chapter_no),
 FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chapter_versions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chapter_id INT UNSIGNED NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 file_key CHAR(40) NOT NULL UNIQUE,
 filename VARCHAR(180) NOT NULL,
 submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE (chapter_id, version_no),
 FOREIGN KEY (chapter_id) REFERENCES chapters(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS version_reviews (
 version_id INT UNSIGNED PRIMARY KEY,
 decision ENUM('changes','reviewed') NOT NULL,
 feedback TEXT NOT NULL,
 reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (version_id) REFERENCES chapter_versions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS progress_updates (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 github_url VARCHAR(500) NOT NULL,
 summary VARCHAR(500) NOT NULL,
 shared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO accounts VALUES
 (1,'student@example.test','Alex Student','$2y$12$J1DOqY3j5mejVsIqDd8CTuiybj54xUPuU0cMawSeBe0PHIPslW6R.','student'),
 (2,'supervisor@example.test','Morgan Supervisor','$2y$12$J1DOqY3j5mejVsIqDd8CTuiybj54xUPuU0cMawSeBe0PHIPslW6R.','supervisor');
INSERT IGNORE INTO projects VALUES (1,'Thesis demonstration project',1,2);
INSERT IGNORE INTO chapters VALUES
 (1,1,1,'Introduction'), (2,1,2,'Literature Review'), (3,1,3,'Methodology');
