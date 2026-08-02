-- Create ID Card Templates table for Kauzariyya Musabaqa
CREATE TABLE IF NOT EXISTS `musabaqa_id_card_templates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `event_id` INT NOT NULL,
  `team_id` INT NULL DEFAULT NULL COMMENT 'NULL for default event template, or specific team ID',
  `background_image` VARCHAR(255) NULL DEFAULT NULL,
  `orientation` VARCHAR(20) NOT NULL DEFAULT 'portrait',
  `card_width` INT NOT NULL DEFAULT 600,
  `card_height` INT NOT NULL DEFAULT 950,
  `layout_config` LONGTEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_event_team` (`event_id`, `team_id`),
  KEY `idx_event_id` (`event_id`),
  KEY `idx_team_id` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
