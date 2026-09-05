-- =====================================================================
--  STUDENT PLANNER — MySQL Database
--  Import this whole file in phpMyAdmin ( Import tab ) OR run:
--      mysql -u root -p < student_planner.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `student_planner`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `student_planner`;

-- ---------------------------------------------------------------------
-- 1. login_accounts  — user accounts (students / admins)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_accounts` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100)  NOT NULL,
  `username`    VARCHAR(60)   NOT NULL UNIQUE,
  `password`    VARCHAR(255)  NOT NULL,               -- password_hash()
  `role`        ENUM('admin','faculty','student') NOT NULL DEFAULT 'student',
  `is_approved` TINYINT(1)    NOT NULL DEFAULT 0,
  `is_online`   TINYINT(1)    NOT NULL DEFAULT 0,
  `theme_color` VARCHAR(7)    NOT NULL DEFAULT '#1a6cf5',
  `avatar_path` VARCHAR(255)  NULL,                    -- path to the uploaded profile picture (e.g. uploads/avatars/xxx.jpg)
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login`  DATETIME      NULL,
  `last_seen`   DATETIME      NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. subjects — a student's classes / courses
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT UNSIGNED NOT NULL,
  `subject_name` VARCHAR(120) NOT NULL,
  `subject_code` VARCHAR(30)  NULL,
  `instructor`   VARCHAR(100) NULL,
  `units`        DECIMAL(3,1) NULL,
  `color`        VARCHAR(7)   NOT NULL DEFAULT '#1a6cf5',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. tasks — the to‑do list / assignments / homework
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tasks` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT UNSIGNED NOT NULL,
  `subject_id`   INT UNSIGNED NULL,
  `title`        VARCHAR(200) NOT NULL,
  `description`  TEXT NULL,
  `due_date`     DATE NULL,
  `due_time`     TIME NULL,
  `priority`     ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `status`       ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  FOREIGN KEY (`user_id`)    REFERENCES `login_accounts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. schedule — weekly class timetable
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schedule` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `day_of_week` ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time`   TIME NOT NULL,
  `room`       VARCHAR(60) NULL,
  FOREIGN KEY (`user_id`)    REFERENCES `login_accounts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. notes — quick notes, optionally linked to a subject
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notes` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NULL,
  `title`      VARCHAR(150) NOT NULL,
  `content`    TEXT NULL,
  `is_pinned`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`)    REFERENCES `login_accounts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. events — calendar events (exams, deadlines, holidays, activities)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(150) NOT NULL,
  `event_date`  DATE NOT NULL,
  `event_type`  ENUM('exam','deadline','holiday','activity','other') NOT NULL DEFAULT 'other',
  `description` TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7a. chat_groups / chat_group_members — private group chats that any
--     account can create. The creator becomes `owner`; owners can invite
--     or remove members, change the group's logo, and delete the group.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_groups` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `logo_path`   VARCHAR(255) NULL,                     -- e.g. uploads/group_logos/xxx.jpg
  `created_by`  INT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `chat_group_members` (
  `group_id`  INT UNSIGNED NOT NULL,
  `user_id`   INT UNSIGNED NOT NULL,
  `role`      ENUM('owner','member') NOT NULL DEFAULT 'member',
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`,`user_id`),
  FOREIGN KEY (`group_id`) REFERENCES `chat_groups`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. messages — shared group chat, visible to every logged-in account,
--    plus every private group chat (group_id NULL = the shared one)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `group_id`    INT UNSIGNED NULL,                     -- NULL = the one shared chat for everyone
  `body`        TEXT NULL,
  `file_path`   VARCHAR(255) NULL,                     -- e.g. uploads/chat/xxx.jpg
  `file_name`   VARCHAR(255) NULL,                     -- original filename shown to users
  `file_type`   VARCHAR(120) NULL,                     -- 'image' or 'file'
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`group_id`) REFERENCES `chat_groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7b. message_hides — per-user "delete for me" flags for the chat.
--     A row here just hides that one message from that one account; the
--     real message row is untouched, so everyone else still sees it.
--     ("Unsend" instead deletes the row above, which removes it for all.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_hides` (
  `message_id` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`message_id`,`user_id`),
  FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  SAMPLE DATA (safe to delete)
--  Default admin  -> username: admin   password: admin123
--  Default student -> username: juan    password: juan123
-- =====================================================================

INSERT INTO `login_accounts` (`name`,`username`,`password`,`role`,`is_approved`) VALUES
('System Admin', 'admin', '$2b$10$ZMM9t7Wif9DC4KuXV0VDr.JGTHH.eGMO0jeU.JIC6ccrzUtFSCoPe', 'admin',   1),
('Juan Dela Cruz','juan',  '$2b$10$eYyiczkw/hik.fpOUV9ZqewbVN9tOsJEnr7UsPwx1SszE9wnFYg1a', 'student', 1);
-- Demo logins:  admin / admin123     and     juan / juan123
-- You can also just register your own account via Register.php — the very
-- first account ever registered is auto-approved as admin.
-- To reset a password manually in MySQL, generate a hash with PHP:
--   php -r "echo password_hash('newpassword', PASSWORD_DEFAULT);"
-- then: UPDATE login_accounts SET password = '<paste hash>' WHERE username = 'admin';

INSERT INTO `subjects` (`user_id`,`subject_name`,`subject_code`,`instructor`,`units`,`color`) VALUES
(2, 'Web Systems and Technologies', 'IT301', 'Prof. R. Santos', 3.0, '#1a6cf5'),
(2, 'Database Management',         'IT302', 'Prof. M. Reyes',  3.0, '#f5c842'),
(2, 'Data Structures & Algorithms','CS201', 'Prof. A. Cruz',   3.0, '#2ecf7a');

INSERT INTO `tasks` (`user_id`,`subject_id`,`title`,`description`,`due_date`,`priority`,`status`) VALUES
(2, 1, 'Finish Login Page UI',      'Apply the dark theme to the login form.', CURDATE(), 'high', 'pending'),
(2, 2, 'ERD for Project',           'Draw entity relationship diagram.', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'medium', 'pending'),
(2, 3, 'Sorting Algorithm Reading', 'Read chapter 4 - Merge Sort & Quick Sort.', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'low', 'completed');

INSERT INTO `schedule` (`user_id`,`subject_id`,`day_of_week`,`start_time`,`end_time`,`room`) VALUES
(2, 1, 'Mon', '08:00:00', '09:30:00', 'Rm 204'),
(2, 2, 'Tue', '10:00:00', '11:30:00', 'Rm 210'),
(2, 3, 'Wed', '13:00:00', '14:30:00', 'Lab 3'),
(2, 1, 'Thu', '08:00:00', '09:30:00', 'Rm 204'),
(2, 2, 'Fri', '10:00:00', '11:30:00', 'Rm 210');

INSERT INTO `notes` (`user_id`,`subject_id`,`title`,`content`,`is_pinned`) VALUES
(2, 1, 'Reminder', 'Bring laptop charger every Monday & Thursday.', 1);

INSERT INTO `events` (`user_id`,`title`,`event_date`,`event_type`,`description`) VALUES
(2, 'Midterm Exams Start', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'exam', 'Coverage: Chapters 1-5'),
(2, 'Project Deadline - IT301', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'deadline', 'Submit final system with documentation.');
