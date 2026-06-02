CREATE TABLE IF NOT EXISTS `musabaqa_breaks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_id` int NOT NULL,
  `stage_type_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_breaks_event` (`event_id`),
  KEY `idx_breaks_stage` (`stage_type_id`),
  KEY `idx_breaks_time` (`start_datetime`, `end_datetime`),
  CONSTRAINT `fk_break_event`
    FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_break_stage`
    FOREIGN KEY (`stage_type_id`) REFERENCES `musabaqa_stage_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
