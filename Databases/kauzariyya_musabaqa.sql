-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 02, 2026 at 04:29 AM
-- Server version: 8.4.3
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kauzariyya_musabaqa`
--

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_activity_logs`
--

CREATE TABLE `musabaqa_activity_logs` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `event_id` int DEFAULT NULL,
  `action_type` varchar(100) DEFAULT NULL,
  `target_table` varchar(100) DEFAULT NULL,
  `target_id` int DEFAULT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `musabaqa_activity_logs`
--

INSERT INTO `musabaqa_activity_logs` (`id`, `user_id`, `event_id`, `action_type`, `target_table`, `target_id`, `description`, `created_at`) VALUES
(1, 1, 3, 'score_creation', 'musabaqa_score_sheets', 1, 'Program score sheet created.', '2026-06-02 03:03:39'),
(2, 1, 3, 'score_creation', 'musabaqa_score_sheets', 2, 'Program score sheet created.', '2026-06-02 03:03:54'),
(3, 1, 3, 'submit_for_approval', 'musabaqa_programs', 7, 'Program scores submitted for approval.', '2026-06-02 03:03:59'),
(4, 1, 3, 'approve_program_scores', 'musabaqa_programs', 7, 'Program scores approved and finalized.', '2026-06-02 03:04:35'),
(5, 1, 3, 'leaderboard_update', 'musabaqa_teams', NULL, 'Leaderboard totals recalculated from approved program scores.', '2026-06-02 03:04:35'),
(6, 1, 1, 'category_update', 'musabaqa_program_categories', 9, 'Program scoring categories updated.', '2026-06-02 03:32:55'),
(7, 1, 1, 'score_creation', 'musabaqa_score_sheets', 3, 'Program score sheet created.', '2026-06-02 03:34:06'),
(8, 1, 1, 'score_creation', 'musabaqa_score_sheets', 4, 'Program score sheet created.', '2026-06-02 03:34:31'),
(9, 1, 1, 'score_creation', 'musabaqa_score_sheets', 5, 'Program score sheet created.', '2026-06-02 03:35:02'),
(10, 1, 1, 'submit_for_approval', 'musabaqa_programs', 9, 'Program scores submitted for approval.', '2026-06-02 03:35:45'),
(11, 1, 1, 'approve_program_scores', 'musabaqa_programs', 9, 'Program scores approved and finalized.', '2026-06-02 03:35:58'),
(12, 1, 1, 'leaderboard_update', 'musabaqa_teams', NULL, 'Leaderboard totals recalculated from approved program scores.', '2026-06-02 03:35:58');

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_breaks`
--

CREATE TABLE `musabaqa_breaks` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `stage_type_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_category_scores`
--

CREATE TABLE `musabaqa_category_scores` (
  `id` int NOT NULL,
  `score_sheet_id` int NOT NULL,
  `judge_no` tinyint NOT NULL,
  `category_id` int NOT NULL,
  `score` decimal(6,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_entry_members`
--

CREATE TABLE `musabaqa_entry_members` (
  `id` int NOT NULL,
  `entry_id` int NOT NULL,
  `team_member_id` int NOT NULL,
  `role_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_events`
--

CREATE TABLE `musabaqa_events` (
  `id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` text,
  `theme_colors` text,
  `scoreboard_mode` enum('system','manual') DEFAULT 'system',
  `intro_enabled` tinyint(1) DEFAULT '1',
  `scoreboard_enabled` tinyint(1) DEFAULT '1',
  `scoreboard_locked` tinyint(1) DEFAULT '0',
  `status` enum('draft','active','completed') DEFAULT 'draft',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `musabaqa_events`
--

INSERT INTO `musabaqa_events` (`id`, `title`, `slug`, `description`, `theme_colors`, `scoreboard_mode`, `intro_enabled`, `scoreboard_enabled`, `scoreboard_locked`, `status`, `start_date`, `end_date`, `created_by`, `created_at`) VALUES
(1, 'Thanafus test', 'tester', 'test', '', 'system', 1, 1, 0, 'draft', '2026-05-28', '2026-05-31', 1, '2026-05-27 15:12:44'),
(3, 'Thanafus 2030', 'teast', '', 'green', 'system', 1, 1, 0, 'draft', '2026-06-26', '2026-06-30', 1, '2026-06-02 01:14:11');

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_judges`
--

CREATE TABLE `musabaqa_judges` (
  `id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_manual_scoreboard`
--

CREATE TABLE `musabaqa_manual_scoreboard` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `team_id` int NOT NULL,
  `score` decimal(10,2) DEFAULT '0.00',
  `remarks` text,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_member_scores`
--

CREATE TABLE `musabaqa_member_scores` (
  `id` int NOT NULL,
  `member_id` int NOT NULL,
  `program_id` int NOT NULL,
  `entry_id` int NOT NULL,
  `score` decimal(10,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_programs`
--

CREATE TABLE `musabaqa_programs` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program_type` enum('individual','group') COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_type_id` int DEFAULT NULL,
  `stage_type_id` int NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `status` enum('active','scoring','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `approval_status` enum('none','submitted','rejected','approved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `submitted_by` int DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_program_categories`
--

CREATE TABLE `musabaqa_program_categories` (
  `id` int NOT NULL,
  `program_id` int NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_marks` decimal(6,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_program_entries`
--

CREATE TABLE `musabaqa_program_entries` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `program_id` int NOT NULL,
  `team_id` int NOT NULL,
  `entry_name` varchar(255) DEFAULT NULL,
  `entry_number` int DEFAULT NULL,
  `status` enum('approved','scoring','completed') NOT NULL DEFAULT 'approved',
  `final_score` decimal(10,2) DEFAULT '0.00',
  `final_rank` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_scores`
--

CREATE TABLE `musabaqa_scores` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `program_id` int NOT NULL,
  `entry_id` int NOT NULL,
  `judge_name` varchar(255) DEFAULT NULL,
  `total_mark` decimal(10,2) NOT NULL,
  `remarks` text,
  `status` enum('draft','pending','approved','rejected') DEFAULT 'draft',
  `entered_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_score_sheets`
--

CREATE TABLE `musabaqa_score_sheets` (
  `id` int NOT NULL,
  `entry_id` int NOT NULL,
  `program_id` int NOT NULL,
  `judge1_total` decimal(6,2) NOT NULL DEFAULT '0.00',
  `judge2_total` decimal(6,2) NOT NULL DEFAULT '0.00',
  `final_total` decimal(6,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','completed','submitted','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_settings`
--

CREATE TABLE `musabaqa_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(255) DEFAULT NULL,
  `setting_value` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_stage_types`
--

CREATE TABLE `musabaqa_stage_types` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `musabaqa_stage_types`
--

INSERT INTO `musabaqa_stage_types` (`id`, `name`) VALUES
(1, 'Normal Stage'),
(2, 'Off Stage');

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_teams`
--

CREATE TABLE `musabaqa_teams` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `team_name` varchar(255) NOT NULL,
  `short_name` varchar(50) DEFAULT NULL,
  `team_color` varchar(30) DEFAULT NULL,
  `number_prefix` int DEFAULT NULL,
  `total_score` decimal(10,2) DEFAULT '0.00',
  `teacher_incharge_id` int DEFAULT NULL,
  `group_leader_student_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `musabaqa_teams`
--

INSERT INTO `musabaqa_teams` (`id`, `event_id`, `team_name`, `short_name`, `team_color`, `number_prefix`, `total_score`, `teacher_incharge_id`, `created_at`) VALUES
(1, 1, 'القين', 'kain', '#10b981', 100, 486.00, NULL, '2026-05-27 15:20:27'),
(2, 1, 'اليقين', 'yaqeen', '#ff0000', 200, 0.00, NULL, '2026-05-28 10:26:56'),
(3, 1, 'الطين', 'theen', '#001eff', 300, 0.00, NULL, '2026-06-01 02:00:53'),
(4, 1, 'الفين', 'theen', '#1eff00', 400, 0.00, NULL, '2026-06-01 02:01:32'),
(5, 3, 'test 0', 'test', '#ffffff', 100, 162.49, NULL, '2026-06-02 01:14:35');

-- --------------------------------------------------------

--
-- Table structure for table `musabaqa_team_members`
--

CREATE TABLE `musabaqa_team_members` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `team_id` int NOT NULL,
  `student_id` int NOT NULL,
  `chest_number` varchar(50) DEFAULT NULL,
  `is_captain` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `musabaqa_team_members`
--

INSERT INTO `musabaqa_team_members` (`id`, `event_id`, `team_id`, `student_id`, `chest_number`, `is_captain`, `status`, `created_at`) VALUES
(1, 1, 1, 34, '1', 0, 'active', '2026-05-28 10:15:54'),
(2, 1, 1, 24, '2', 0, 'active', '2026-05-28 10:15:54'),
(3, 1, 1, 26, '3', 0, 'active', '2026-05-28 10:15:54'),
(4, 1, 1, 35, '4', 0, 'active', '2026-05-28 10:15:54'),
(5, 1, 1, 28, '5', 0, 'active', '2026-05-28 10:15:54'),
(6, 1, 1, 29, '6', 0, 'active', '2026-05-28 10:15:54'),
(7, 1, 1, 27, '7', 0, 'active', '2026-05-28 10:15:54'),
(8, 1, 1, 44, '8', 0, 'active', '2026-05-28 10:22:58'),
(9, 1, 1, 16, '9', 0, 'active', '2026-05-28 10:22:58'),
(10, 1, 1, 38, '10', 0, 'active', '2026-05-28 10:22:58'),
(11, 1, 1, 13, '11', 0, 'active', '2026-05-28 10:22:58'),
(12, 1, 1, 66, '12', 0, 'active', '2026-05-28 10:22:58'),
(13, 1, 1, 79, '13', 0, 'active', '2026-05-28 10:22:58'),
(14, 1, 1, 37, '14', 0, 'active', '2026-05-28 10:22:58'),
(15, 1, 1, 10, '15', 0, 'active', '2026-05-28 10:22:58'),
(16, 1, 1, 5, '16', 0, 'active', '2026-05-28 10:22:58'),
(17, 1, 1, 4, '17', 0, 'active', '2026-05-28 10:22:58'),
(18, 1, 1, 11, '18', 0, 'active', '2026-05-28 10:22:58'),
(19, 1, 1, 12, '19', 0, 'active', '2026-05-28 10:22:58'),
(20, 1, 1, 47, '20', 0, 'active', '2026-05-28 10:22:58'),
(21, 1, 1, 45, '21', 0, 'active', '2026-05-28 10:22:58'),
(22, 1, 1, 48, '22', 0, 'active', '2026-05-28 10:22:58'),
(23, 1, 1, 49, '23', 0, 'active', '2026-05-28 10:22:58'),
(24, 1, 1, 77, '24', 0, 'active', '2026-05-28 10:22:58'),
(25, 1, 1, 70, '25', 0, 'active', '2026-05-28 10:22:58'),
(26, 1, 1, 53, '26', 0, 'active', '2026-05-28 10:22:58'),
(27, 1, 1, 46, '27', 0, 'active', '2026-05-28 10:22:58'),
(28, 1, 1, 72, '28', 0, 'active', '2026-05-28 10:22:58'),
(29, 1, 1, 56, '29', 0, 'active', '2026-05-28 10:22:58'),
(30, 1, 1, 51, '30', 0, 'active', '2026-05-28 10:22:58'),
(31, 1, 1, 43, '31', 0, 'active', '2026-05-28 10:22:58'),
(32, 1, 1, 60, '32', 0, 'active', '2026-05-28 10:22:58'),
(33, 1, 1, 58, '33', 0, 'active', '2026-05-28 10:22:58'),
(34, 1, 1, 61, '34', 0, 'active', '2026-05-28 10:22:58'),
(35, 1, 1, 65, '35', 0, 'active', '2026-05-28 10:22:58'),
(36, 1, 2, 23, '1', 0, 'active', '2026-05-28 10:35:02'),
(37, 1, 2, 19, '2', 0, 'active', '2026-05-28 10:35:02'),
(38, 1, 2, 75, '3', 0, 'active', '2026-05-28 10:35:02'),
(39, 1, 2, 32, '4', 0, 'active', '2026-05-28 10:35:02'),
(40, 1, 2, 39, '5', 0, 'active', '2026-05-28 10:35:02'),
(41, 1, 2, 20, '6', 0, 'active', '2026-05-28 10:35:02'),
(42, 1, 2, 21, '7', 0, 'active', '2026-05-28 10:35:02'),
(43, 1, 2, 33, '8', 0, 'active', '2026-05-28 10:35:02'),
(44, 1, 2, 40, '9', 0, 'active', '2026-05-28 10:35:02'),
(45, 1, 2, 18, '10', 0, 'active', '2026-05-28 10:35:02'),
(46, 1, 2, 31, '11', 0, 'active', '2026-05-28 10:35:02'),
(47, 1, 2, 22, '12', 0, 'active', '2026-05-28 10:35:02'),
(48, 1, 2, 25, '13', 0, 'active', '2026-05-28 10:35:02'),
(49, 1, 2, 30, '14', 0, 'active', '2026-05-28 10:35:02'),
(50, 1, 2, 36, '15', 0, 'active', '2026-05-28 10:35:02'),
(51, 1, 2, 15, '16', 0, 'active', '2026-05-28 10:35:02'),
(52, 1, 2, 14, '17', 0, 'active', '2026-05-28 10:35:02'),
(53, 1, 2, 17, '18', 0, 'active', '2026-05-28 10:35:02'),
(54, 1, 2, 76, '19', 0, 'active', '2026-05-28 10:35:02'),
(55, 1, 2, 68, '20', 0, 'active', '2026-05-28 10:35:02'),
(56, 1, 2, 67, '21', 0, 'active', '2026-05-28 10:35:02'),
(57, 1, 2, 3, '22', 0, 'active', '2026-05-28 10:35:02'),
(58, 1, 2, 8, '23', 0, 'active', '2026-05-28 10:35:02'),
(59, 1, 2, 9, '24', 0, 'active', '2026-05-28 10:35:02'),
(60, 1, 2, 6, '25', 0, 'active', '2026-05-28 10:35:02'),
(61, 1, 2, 1, '26', 0, 'active', '2026-05-28 10:35:02'),
(62, 1, 2, 7, '27', 0, 'active', '2026-05-28 10:35:02'),
(63, 1, 2, 41, '28', 0, 'active', '2026-05-28 10:35:02'),
(64, 1, 2, 42, '29', 0, 'active', '2026-05-28 10:35:02'),
(65, 1, 2, 71, '30', 0, 'active', '2026-05-28 10:35:02'),
(66, 1, 2, 55, '31', 0, 'active', '2026-05-28 10:35:02'),
(67, 1, 2, 54, '32', 0, 'active', '2026-05-28 10:35:02'),
(68, 1, 2, 52, '33', 0, 'active', '2026-05-28 10:35:02'),
(69, 1, 2, 57, '34', 0, 'active', '2026-05-28 10:35:02'),
(70, 1, 2, 50, '35', 0, 'active', '2026-05-28 10:35:02'),
(71, 1, 2, 69, '36', 0, 'active', '2026-05-28 10:35:02'),
(72, 1, 2, 62, '37', 0, 'active', '2026-05-28 10:35:02'),
(73, 1, 2, 59, '38', 0, 'active', '2026-05-28 10:35:02'),
(74, 1, 2, 63, '39', 0, 'active', '2026-05-28 10:35:02'),
(75, 1, 2, 64, '40', 0, 'active', '2026-05-28 10:35:02'),
(76, 1, 2, 74, '41', 0, 'active', '2026-05-28 10:35:02'),
(77, 1, 2, 73, '42', 0, 'active', '2026-05-28 10:35:02'),
(79, 3, 5, 19, '101', 0, 'active', '2026-06-02 01:14:57'),
(80, 3, 5, 75, '102', 0, 'active', '2026-06-02 01:14:57'),
(81, 3, 5, 32, '103', 0, 'active', '2026-06-02 01:14:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `musabaqa_activity_logs`
--
ALTER TABLE `musabaqa_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_log_event` (`event_id`);

--
-- Indexes for table `musabaqa_breaks`
--
ALTER TABLE `musabaqa_breaks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_breaks_event` (`event_id`),
  ADD KEY `idx_breaks_stage` (`stage_type_id`),
  ADD KEY `idx_breaks_time` (`start_datetime`,`end_datetime`);

--
-- Indexes for table `musabaqa_category_scores`
--
ALTER TABLE `musabaqa_category_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_score_judge` (`score_sheet_id`,`judge_no`,`category_id`),
  ADD KEY `idx_category_scores_category` (`category_id`);

--
-- Indexes for table `musabaqa_entry_members`
--
ALTER TABLE `musabaqa_entry_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_entry_member_entry` (`entry_id`),
  ADD KEY `fk_entry_member_team_member` (`team_member_id`);

--
-- Indexes for table `musabaqa_events`
--
ALTER TABLE `musabaqa_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `musabaqa_judges`
--
ALTER TABLE `musabaqa_judges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_judges_active` (`active`);

--
-- Indexes for table `musabaqa_manual_scoreboard`
--
ALTER TABLE `musabaqa_manual_scoreboard`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_manual_event` (`event_id`),
  ADD KEY `fk_manual_team` (`team_id`);

--
-- Indexes for table `musabaqa_member_scores`
--
ALTER TABLE `musabaqa_member_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_member_program_entry` (`member_id`,`program_id`,`entry_id`),
  ADD KEY `idx_member_scores_program` (`program_id`),
  ADD KEY `idx_member_scores_entry` (`entry_id`);

--
-- Indexes for table `musabaqa_programs`
--
ALTER TABLE `musabaqa_programs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_program_event` (`event_id`),
  ADD KEY `fk_program_stage` (`stage_type_id`),
  ADD KEY `fk_program_class_type` (`class_type_id`);

--
-- Indexes for table `musabaqa_program_categories`
--
ALTER TABLE `musabaqa_program_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_program_categories_program` (`program_id`);

--
-- Indexes for table `musabaqa_program_entries`
--
ALTER TABLE `musabaqa_program_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_entry_event` (`event_id`),
  ADD KEY `fk_entry_program` (`program_id`),
  ADD KEY `fk_entry_team` (`team_id`);

-- Indexes for table `musabaqa_scores`
--
ALTER TABLE `musabaqa_scores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_score_event` (`event_id`),
  ADD KEY `fk_score_program` (`program_id`),
  ADD KEY `fk_score_entry` (`entry_id`);

--
-- Indexes for table `musabaqa_score_sheets`
--
ALTER TABLE `musabaqa_score_sheets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_score_sheet_entry` (`entry_id`),
  ADD KEY `idx_score_sheets_program` (`program_id`),
  ADD KEY `idx_score_sheets_status` (`status`);

--
-- Indexes for table `musabaqa_settings`
--
ALTER TABLE `musabaqa_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `musabaqa_stage_types`
--
ALTER TABLE `musabaqa_stage_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `musabaqa_teams`
--
ALTER TABLE `musabaqa_teams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team_event` (`event_id`);

--
-- Indexes for table `musabaqa_team_members`
--
ALTER TABLE `musabaqa_team_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_member_event` (`event_id`),
  ADD KEY `fk_member_team` (`team_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `musabaqa_activity_logs`
--
ALTER TABLE `musabaqa_activity_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `musabaqa_breaks`
--
ALTER TABLE `musabaqa_breaks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `musabaqa_category_scores`
--
ALTER TABLE `musabaqa_category_scores`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `musabaqa_entry_members`
--
ALTER TABLE `musabaqa_entry_members`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `musabaqa_events`
--
ALTER TABLE `musabaqa_events`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `musabaqa_judges`
--
ALTER TABLE `musabaqa_judges`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `musabaqa_manual_scoreboard`
--
ALTER TABLE `musabaqa_manual_scoreboard`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `musabaqa_member_scores`
--
ALTER TABLE `musabaqa_member_scores`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `musabaqa_programs`
--
ALTER TABLE `musabaqa_programs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `musabaqa_program_categories`
--
ALTER TABLE `musabaqa_program_categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `musabaqa_program_entries`
--
ALTER TABLE `musabaqa_program_entries`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

-- AUTO_INCREMENT for table `musabaqa_scores`
--
ALTER TABLE `musabaqa_scores`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `musabaqa_score_sheets`
--
ALTER TABLE `musabaqa_score_sheets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `musabaqa_settings`
--
ALTER TABLE `musabaqa_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `musabaqa_stage_types`
--
ALTER TABLE `musabaqa_stage_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `musabaqa_teams`
--
ALTER TABLE `musabaqa_teams`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `musabaqa_team_members`
--
ALTER TABLE `musabaqa_team_members`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `musabaqa_activity_logs`
--
ALTER TABLE `musabaqa_activity_logs`
  ADD CONSTRAINT `fk_log_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `musabaqa_breaks`
--
ALTER TABLE `musabaqa_breaks`
  ADD CONSTRAINT `fk_break_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_break_stage` FOREIGN KEY (`stage_type_id`) REFERENCES `musabaqa_stage_types` (`id`);

--
-- Constraints for table `musabaqa_category_scores`
--
ALTER TABLE `musabaqa_category_scores`
  ADD CONSTRAINT `fk_category_score_category` FOREIGN KEY (`category_id`) REFERENCES `musabaqa_program_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_category_score_sheet` FOREIGN KEY (`score_sheet_id`) REFERENCES `musabaqa_score_sheets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_entry_members`
--
ALTER TABLE `musabaqa_entry_members`
  ADD CONSTRAINT `fk_entry_member_entry` FOREIGN KEY (`entry_id`) REFERENCES `musabaqa_program_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_entry_member_team_member` FOREIGN KEY (`team_member_id`) REFERENCES `musabaqa_team_members` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_manual_scoreboard`
--
ALTER TABLE `musabaqa_manual_scoreboard`
  ADD CONSTRAINT `fk_manual_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_manual_team` FOREIGN KEY (`team_id`) REFERENCES `musabaqa_teams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_member_scores`
--
ALTER TABLE `musabaqa_member_scores`
  ADD CONSTRAINT `fk_member_score_entry` FOREIGN KEY (`entry_id`) REFERENCES `musabaqa_program_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_member_score_member` FOREIGN KEY (`member_id`) REFERENCES `musabaqa_team_members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_member_score_program` FOREIGN KEY (`program_id`) REFERENCES `musabaqa_programs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_programs`
--
ALTER TABLE `musabaqa_programs`
  ADD CONSTRAINT `fk_program_class_type` FOREIGN KEY (`class_type_id`) REFERENCES `kauzariyya`.`class_types` (`id`),
  ADD CONSTRAINT `fk_program_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_program_stage` FOREIGN KEY (`stage_type_id`) REFERENCES `musabaqa_stage_types` (`id`);

--
-- Constraints for table `musabaqa_program_categories`
--
ALTER TABLE `musabaqa_program_categories`
  ADD CONSTRAINT `fk_program_category_program` FOREIGN KEY (`program_id`) REFERENCES `musabaqa_programs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_program_entries`
--
ALTER TABLE `musabaqa_program_entries`
  ADD CONSTRAINT `fk_entry_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_entry_program` FOREIGN KEY (`program_id`) REFERENCES `musabaqa_programs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_entry_team` FOREIGN KEY (`team_id`) REFERENCES `musabaqa_teams` (`id`) ON DELETE CASCADE;

-- Constraints for table `musabaqa_scores`
--
ALTER TABLE `musabaqa_scores`
  ADD CONSTRAINT `fk_score_entry` FOREIGN KEY (`entry_id`) REFERENCES `musabaqa_program_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_score_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_score_program` FOREIGN KEY (`program_id`) REFERENCES `musabaqa_programs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_score_sheets`
--
ALTER TABLE `musabaqa_score_sheets`
  ADD CONSTRAINT `fk_score_sheet_entry` FOREIGN KEY (`entry_id`) REFERENCES `musabaqa_program_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_score_sheet_program` FOREIGN KEY (`program_id`) REFERENCES `musabaqa_programs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_teams`
--
ALTER TABLE `musabaqa_teams`
  ADD CONSTRAINT `fk_team_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `musabaqa_team_members`
--
ALTER TABLE `musabaqa_team_members`
  ADD CONSTRAINT `fk_member_event` FOREIGN KEY (`event_id`) REFERENCES `musabaqa_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_member_team` FOREIGN KEY (`team_id`) REFERENCES `musabaqa_teams` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
