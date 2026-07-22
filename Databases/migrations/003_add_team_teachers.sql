-- =========================================================
-- MIGRATION 003: CREATE MUSABAQA_TEAM_TEACHERS TABLE
-- =========================================================

CREATE TABLE IF NOT EXISTS `musabaqa_team_teachers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT NOT NULL,
  `team_id` INT NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `role` VARCHAR(50) DEFAULT 'mentor',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_event_team_teacher` (`event_id`, `team_id`, `teacher_id`),
  KEY `idx_team_event` (`team_id`, `event_id`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
