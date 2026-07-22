-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 27, 2026 at 05:08 AM
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
-- Database: `kauzariyya`
--

-- --------------------------------------------------------

--
-- Table structure for table `authorities`
--

CREATE TABLE `authorities` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `type` enum('fixed','temporary') DEFAULT 'fixed',
  `context` varchar(100) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` int NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `maddhab_id` int UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`id`, `class_id`, `subject_id`, `maddhab_id`, `title`, `created_at`, `updated_at`) VALUES
(1, 1, 1, NULL, 'دروس اللغة', '2026-03-10 13:55:24', '2026-03-10 13:55:24'),
(2, 1, 5, NULL, 'مبادئ النحو', '2026-03-10 13:56:30', '2026-03-10 13:56:30'),
(3, 1, 5, NULL, 'شرح مائة عامل', '2026-03-10 13:57:31', '2026-03-10 13:57:31'),
(4, 1, 3, 1, 'الفقه الميسر', '2026-03-10 14:04:08', '2026-03-11 09:25:40'),
(5, 1, 3, 2, 'عشرة كتب', '2026-03-10 14:04:34', '2026-03-11 09:25:40'),
(6, 1, 6, NULL, 'سرف', '2026-03-10 14:10:19', '2026-03-10 14:10:19'),
(7, 1, 1, NULL, 'القراءة الواضحة', '2026-03-10 14:11:22', '2026-03-10 14:11:22'),
(8, 1, 4, NULL, 'NIOS / SCOLE KERALA', '2026-03-10 14:13:10', '2026-03-10 14:13:10'),
(9, 1, 1, NULL, 'ہماری کتاب', '2026-03-10 14:15:51', '2026-03-10 14:15:51'),
(10, 1, 3, 2, 'ശാഫീ ഫികഹ് ', '2026-03-10 14:16:34', '2026-03-11 09:25:40'),
(11, 2, 6, NULL, 'علم الصيغة', '2026-03-10 14:18:26', '2026-03-10 14:18:26'),
(12, 2, 11, NULL, 'رياض الصالحين', '2026-03-10 14:18:50', '2026-03-10 14:18:50'),
(13, 2, 2, NULL, 'القراءة الراشدة', '2026-03-10 14:19:13', '2026-03-10 14:19:13'),
(14, 2, 5, NULL, 'أساسيات النحو', '2026-03-10 14:20:20', '2026-03-10 14:20:20'),
(15, 2, 3, 1, 'الفقه الميسر (ثاني)', '2026-03-10 14:20:40', '2026-03-11 09:25:01'),
(16, 2, 3, 2, 'عمدة السالك', '2026-03-10 14:21:29', '2026-03-11 09:25:01'),
(17, 2, 14, NULL, 'تعليم المتعلم', '2026-03-10 14:23:02', '2026-03-10 14:23:02'),
(18, 2, 4, NULL, 'NIOS / SCOLE KERALA', '2026-03-10 14:23:48', '2026-03-10 14:23:48'),
(19, 1, 15, NULL, 'تجويد', '2026-03-10 14:25:17', '2026-03-10 14:25:17'),
(20, 2, 15, NULL, 'فواید مکيۃ', '2026-03-10 14:27:31', '2026-03-10 14:27:31'),
(21, 3, 10, NULL, 'تفسير عثماني', '2026-03-10 14:28:53', '2026-03-10 14:28:53'),
(22, 3, 5, NULL, 'القواعد الأساسية', '2026-03-10 14:29:21', '2026-03-10 14:29:21'),
(23, 3, 3, 1, 'هداية أولين', '2026-03-10 14:29:44', '2026-03-11 09:24:39'),
(24, 3, 3, 2, 'فتح المعين', '2026-03-10 14:30:15', '2026-03-11 09:24:39'),
(25, 3, 16, NULL, 'نور اليقين', '2026-03-10 14:31:51', '2026-03-10 14:31:51'),
(26, 3, 4, NULL, 'NIOS / SCOLE KERALA', '2026-03-10 14:32:24', '2026-03-10 14:32:24'),
(27, 3, 12, 1, 'أصول الشاشي', '2026-03-10 14:32:56', '2026-03-11 09:24:39'),
(28, 3, 12, 2, 'الخلاصة', '2026-03-10 14:33:18', '2026-03-11 09:24:39'),
(29, 3, 15, NULL, 'خلاصة البيان', '2026-03-10 14:33:41', '2026-03-10 14:33:41'),
(30, 2, 1, NULL, 'ہماری رسول', '2026-03-10 14:34:21', '2026-03-10 14:34:21'),
(31, 4, 3, 1, 'هداية أولين', '2026-03-11 06:16:09', '2026-03-11 09:24:06'),
(33, 4, 3, 2, 'محلي', '2026-03-11 06:21:11', '2026-03-11 09:24:06'),
(34, 4, 12, 1, 'أصول الشاشي', '2026-03-11 06:26:02', '2026-03-11 09:24:06'),
(35, 4, 7, NULL, 'البلاغة الواضحة', '2026-03-11 06:26:54', '2026-03-11 06:26:54'),
(36, 4, 17, NULL, 'تسهيل المنطق', '2026-03-11 06:29:49', '2026-03-11 06:29:49'),
(37, 4, 14, NULL, 'شرح التأديب', '2026-03-11 06:30:18', '2026-03-11 06:30:18'),
(38, 4, 12, 2, 'اللمع', '2026-03-11 06:30:36', '2026-03-11 09:24:06'),
(39, 4, 12, 1, 'نور الأنوار', '2026-03-11 06:31:02', '2026-03-11 09:24:06'),
(40, 4, 8, NULL, 'عقيدة الطحاوي', '2026-03-11 06:31:26', '2026-03-11 06:31:26'),
(41, 4, 11, NULL, 'مشكات المصابيح', '2026-03-11 06:35:17', '2026-03-11 06:35:17'),
(42, 4, 10, NULL, 'تفسير عثماني', '2026-03-11 06:36:16', '2026-03-11 06:36:16');

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int NOT NULL,
  `class_type_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(150) DEFAULT NULL,
  `year` int DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `class_type_id`, `name`, `slug`, `year`, `status`, `created_at`, `updated_at`) VALUES
(1, 2, 'الثانوية الأولى', 'الثانوية-الأولى-2026', 2026, 'active', '2026-03-08 10:34:31', '2026-03-08 10:34:31'),
(2, 2, 'الثانوية الثانية', 'الثانوية-الثانية-2025', 2025, 'active', '2026-03-08 10:36:58', '2026-03-08 10:36:58'),
(3, 2, 'الثاتوية الثالثة', 'الثاتوية-الثالثة-2024', 2024, 'active', '2026-03-08 10:37:22', '2026-03-08 10:37:22'),
(4, 1, 'العالية الأولى', 'العالية-الأولى-2023', 2023, 'active', '2026-03-08 10:37:56', '2026-03-08 10:37:56'),
(5, 1, 'العالية الثانية', 'العالية-الثانية-2022', 2022, 'active', '2026-03-08 10:38:23', '2026-03-08 10:38:23'),
(6, 1, 'دورة الحيث', 'دورة-الحيث-2021', 2021, 'active', '2026-03-08 10:38:34', '2026-03-08 10:38:34'),
(7, 3, 'التحصص في القراءة', 'التحصص-في-القراءة-2026', 2026, 'active', '2026-03-08 10:38:50', '2026-03-08 10:38:50'),
(8, 3, 'التخصص في الفقه (السنة الأولى)', 'التخصص-في-الفقه-(السنة-الأولى)-2026', 2026, 'active', '2026-03-08 10:39:00', '2026-03-08 10:39:00'),
(9, 3, '(التخصص في الفقه (السنة الثانية', '(التخصص-في-الفقه-(السنة-الثانية-2025', 2025, 'active', '2026-03-08 10:39:19', '2026-03-08 10:39:19');

-- --------------------------------------------------------

--
-- Table structure for table `class_types`
--

CREATE TABLE `class_types` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) DEFAULT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `class_types`
--

INSERT INTO `class_types` (`id`, `name`, `slug`, `description`, `created_at`, `updated_at`) VALUES
(1, 'العالية', 'العالية', '', '2026-03-08 10:28:59', NULL),
(2, 'الثانوية', 'الثانوية', '', '2026-03-08 10:29:11', NULL),
(3, 'التحصص', 'التحصص', '', '2026-03-08 10:29:14', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `maddhabs`
--

CREATE TABLE `maddhabs` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_arabic` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `maddhabs`
--

INSERT INTO `maddhabs` (`id`, `name`, `name_arabic`, `created_at`) VALUES
(1, 'Hanafi', 'حنفي', '2026-03-09 05:59:03'),
(2, 'Shafi', 'شافعي', '2026-03-09 05:59:03'),
(3, 'Maliki', 'مالكي', '2026-03-09 05:59:03'),
(4, 'Hanbali', 'حنبلي', '2026-03-09 05:59:03');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL UNIQUE,
  `slug` varchar(100) NOT NULL UNIQUE,
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text,
  `event_id` int UNSIGNED NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_roles_event_idx` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `name`, `slug`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Main', 'main', 'System-wide core pages and roles', 1, NOW(), NOW()),
(2, 'Thanafus 2026-27', 'thanafus-2026-27', 'Special roles for the Thanafus 2026-27 competition space', 0, NOW(), NOW());

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `slug`, `description`, `event_id`, `is_system`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin', 'All permission', 1, 1, '2026-03-08 10:20:46', '2026-05-26 11:22:32'),
(2, 'teacher', 'teacher', 'teacher permission', 1, 0, '2026-03-09 06:08:14', '2026-05-26 11:22:43'),
(3, 'student', 'student', 'Student role', 1, 0, '2026-03-15 04:13:17', '2026-03-15 04:13:17'),
(5, 'Members Info Manager', 'members-info-manager', 'Controls access to ID cards, chest numbers, etc.', 2, 0, NOW(), NOW()),
(6, 'Entries Assigner', 'entries-assigner', 'Assigns students to contest entries.', 2, 0, NOW(), NOW()),
(7, 'Score Uploader', 'score-uploader', 'Uploads judge marks and score cards.', 2, 0, NOW(), NOW()),
(8, 'TV Controller', 'tv-controller', 'Controls screen feeds and live TV display.', 2, 0, NOW(), NOW());

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`id`, `name`, `slug`, `event_id`, `category`, `type`) VALUES
(1, 'Qira\'at', 'qiraat', 2, 'Sub Junior', 'Individual'),
(2, 'Elocution', 'elocution', 2, 'Junior', 'Individual'),
(3, 'Essay Writing', 'essay-writing', 2, 'Senior', 'Individual'),
(4, 'Islamic Quiz', 'islamic-quiz', 2, 'Senior', 'Group'),
(5, 'Hifz', 'hifz', 2, 'Junior', 'Individual');

--
-- Dumping data for table `tv_settings`
--

INSERT INTO `tv_settings` (`id`, `active_program_id`, `display_type`) VALUES
(1, NULL, 'scoreboard');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `user_id` int UNSIGNED NOT NULL,
  `permission_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_authorities`
--

CREATE TABLE `user_authorities` (
  `user_id` int UNSIGNED NOT NULL,
  `authority_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `key` varchar(100) NOT NULL,
  `value` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `specialisations`
--

CREATE TABLE `specialisations` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `display_name` varchar(150) DEFAULT NULL,
  `name_arabic` varchar(200) DEFAULT NULL,
  `place` varchar(100) DEFAULT NULL,
  `admission_no` varchar(50) DEFAULT NULL,
  `class_id` int UNSIGNED DEFAULT NULL,
  `maddhab_id` int UNSIGNED DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `guardian_name` varchar(200) DEFAULT NULL,
  `guardian_phone` varchar(30) DEFAULT NULL,
  `status` enum('active','graduated','left','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `full_name`, `display_name`, `name_arabic`, `place`, `admission_no`, `class_id`, `maddhab_id`, `phone`, `email`, `dob`, `guardian_name`, `guardian_phone`, `status`, `created_at`, `updated_at`) VALUES
(1, 7, 'Hudaifa', 'Hudaifa kottayam', 'حذيفة', 'Ettumanoor', NULL, 4, 1, '9037861024', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:21:54', '2026-03-16 10:33:37'),
(2, NULL, 'Rashad', 'Rashad TVM', 'رشاد', 'TVM', NULL, 4, 2, '94963 92425', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:23:12', '2026-03-09 07:51:58'),
(3, NULL, 'Aadam', 'Aadam', 'آدم', 'TVM', NULL, 4, 2, '97461 87288', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:24:44', '2026-03-09 07:51:58'),
(4, NULL, 'Salman', 'Salman TVM', 'سلمان', 'TVM', NULL, 4, 2, '96336 79003', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:25:20', '2026-03-09 07:51:58'),
(5, NULL, 'Mahin Sha', 'Mahin Sha', 'ماح شاه', 'Muvattupuzha', NULL, 4, 1, '89431 40844', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:28:05', '2026-03-09 07:51:58'),
(6, NULL, 'Haroon', 'Haroon', 'هارون', 'Thookupalam', NULL, 4, 1, '8075 489 927', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:28:50', '2026-03-09 07:51:58'),
(7, NULL, 'Shaad', 'Shaad', 'شاد', 'Kasaragod', NULL, 4, 2, '6282 476 461', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:29:37', '2026-03-09 07:51:58'),
(8, NULL, 'Anfaal', 'Anfal ', 'أنفال', 'Thodupuzha', NULL, 4, 1, '73065 28443', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:31:39', '2026-03-09 07:51:58'),
(9, NULL, 'Asjad', 'Asjad Rahman', 'أسجد', 'Kollam', NULL, 4, 1, '755 996 4183', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:32:37', '2026-03-09 07:51:58'),
(10, NULL, 'Hamzath Ali', 'Hamzath Ali', 'حمزة علي', 'Vazhakkulam', NULL, 4, 2, '94953 55300', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:33:23', '2026-03-09 07:51:58'),
(11, NULL, 'Shahul', 'Shahul', 'شاهل', 'Muvattupuzha', NULL, 4, 1, '97781 68543', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:34:34', '2026-03-09 07:51:58'),
(12, NULL, 'Swalih', 'Swalih ', 'صالح', 'Thodupuzha', NULL, 4, 1, '6282 136 908', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:35:22', '2026-03-09 07:51:58'),
(13, NULL, 'Muhsin', '', '', 'Edathala', NULL, 2, 1, '92078 66709', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:43:13', '2026-03-09 10:35:39'),
(14, NULL, 'Farhan', '', '', 'Kumali', NULL, 2, 1, '73065 61146', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:43:43', '2026-03-09 10:35:39'),
(15, NULL, 'Ahsan', '', '', 'Kanjar', NULL, 2, 1, '94473 43885', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:45:37', '2026-03-09 10:35:39'),
(16, NULL, 'Abdulla Jaleel', '', '', 'Muvattupuzha', NULL, 2, 1, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:46:10', '2026-03-09 10:35:39'),
(17, NULL, 'Safwan', '', '', 'Chilavu', NULL, 2, NULL, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:46:58', '2026-03-09 10:35:39'),
(18, NULL, 'Faiz', 'Faiz Erpt', 'فائز', 'Erattupeta', NULL, 3, 1, '97446 41826', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:48:17', '2026-03-09 08:10:31'),
(19, NULL, 'Adheeb', 'Adheeb', 'أديب', 'Palakkad', NULL, 3, 1, '97782 23825', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:49:50', '2026-03-09 08:10:31'),
(20, NULL, 'Amanulla', 'Amanulla', 'أمان الله', 'Pallikkara', NULL, 3, 2, '98473 09667', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:52:25', '2026-03-09 08:10:31'),
(21, NULL, 'Ameen', 'Ameen Edathala', 'أمين', 'Edathala', NULL, 3, 1, ' 90373 80923', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:54:22', '2026-03-09 08:10:31'),
(22, NULL, 'In\'am', 'In\'am kanjar', 'إنعام', 'kanjar', NULL, 3, 1, '6282 342 864', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:55:15', '2026-03-09 08:10:31'),
(23, NULL, 'Abrar', 'Abrar ', 'أبرار', 'Kanjar', NULL, 3, NULL, '8075 751 552', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:57:43', '2026-03-09 08:10:31'),
(24, NULL, 'Fardheen', 'Fardheen', 'فردين', 'thodupuzha', NULL, 3, 1, '755 998 4689', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:57:59', '2026-03-09 08:10:31'),
(25, NULL, 'Sufyan', 'Sufyan Edathala', 'سفيان', 'Edathala', NULL, 3, 2, '97469 38733', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:58:57', '2026-03-09 08:10:31'),
(26, NULL, 'In\'am', 'In\'am MVPT', 'إنعام', 'Muvattupuzha', NULL, 3, 2, '73562 46129', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:59:14', '2026-03-09 08:10:31'),
(27, NULL, 'Yaseen', 'Yaseen P', 'يس', 'Pallarimankalam', NULL, 3, 2, '81118 10739', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:59:51', '2026-03-09 08:10:31'),
(28, NULL, 'Yaseen', 'Yaseen Edathala', 'يس', 'Edathala', NULL, 3, 2, '9744706410', NULL, NULL, NULL, NULL, 'active', '2026-03-09 06:59:58', '2026-03-09 08:10:31'),
(29, NULL, 'Yaseen', 'Yaseen K', 'يس', 'Kottarakkara', NULL, 3, NULL, '96336 25682', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:00:08', '2026-03-09 08:10:31'),
(30, NULL, 'Yaseen', 'Yaseen TDP', 'يس', 'Thodupuzha', NULL, 3, 2, '9037081248', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:00:20', '2026-03-09 08:10:31'),
(31, NULL, 'Hafis', 'Hafis Kodungallur', 'حافظ', 'Kodungallur', NULL, 3, 2, '98952 68422', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:03:36', '2026-03-09 08:10:31'),
(32, NULL, 'Ahamed', 'Ahamed', 'أحمد', 'Kollam', NULL, 3, 2, '94000 38616', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:07:57', '2026-03-09 08:10:31'),
(33, NULL, 'Ashik VS', 'Ashik VS', 'عاشق', 'Muvattupuzha', NULL, 3, 1, '79027 75575', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:09:06', '2026-03-09 08:17:37'),
(34, NULL, 'Ashik', 'Ashik Thookupalam', 'عاشق', 'Thookupalam', NULL, 3, 1, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:09:21', '2026-03-09 08:10:31'),
(35, NULL, 'Muzzammil', 'Muzzammil Navas', 'مزمل', 'Thodupuzha', NULL, 3, 1, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:09:48', '2026-03-09 08:10:31'),
(36, NULL, 'Zaid Ali', 'Zaid ali', 'زيد علي', 'vennala', NULL, 3, NULL, '97784 66618', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:21:28', '2026-03-09 08:10:31'),
(37, NULL, 'Badusha', 'Badusha', 'باد شاه', 'Kothamangalam', NULL, 4, NULL, '85904 17029', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:48:36', '2026-03-09 07:48:36'),
(38, NULL, 'Mezin', 'Mezin', '', 'Kannur', NULL, 2, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:54:20', '2026-03-09 07:54:20'),
(39, NULL, 'Akmal', 'Akmal', 'أكمل', 'Maysore', NULL, 3, 2, '88672 90521', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:56:28', '2026-05-26 10:43:25'),
(40, NULL, 'Badrudheen', 'Badarudheen ', 'بدر الدين', 'Pallarimankalam', NULL, 3, 2, '79079 82301', NULL, NULL, NULL, NULL, 'active', '2026-03-09 07:59:01', '2026-05-26 10:43:25'),
(41, NULL, 'Abdulla', 'Abdulla PKD', '', 'Palakkad', NULL, 5, 1, '81390 29440', NULL, NULL, NULL, NULL, 'active', '2026-03-09 08:12:13', '2026-03-09 08:12:13'),
(42, NULL, 'Habeebulla', 'Habeebulla PKD', '', 'Palakkad', NULL, 5, 1, '95675 92130', NULL, NULL, NULL, NULL, 'active', '2026-03-09 08:12:46', '2026-03-09 08:12:46'),
(43, NULL, 'Ziyad Ali', 'Ziyad Ali', '', 'Edappalli', NULL, 5, 2, '79947 13109', NULL, NULL, NULL, NULL, 'active', '2026-03-09 08:17:14', '2026-03-09 12:16:20'),
(44, NULL, ' Abid ', '', '', 'Vannapauram', NULL, 2, 2, '96565 09266', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:10:44', '2026-03-09 12:10:44'),
(45, NULL, 'Anzil', 'Anzil Perumbavoor', '', 'Perumbavoor', NULL, 5, 2, '77369 81393', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:11:43', '2026-03-09 14:01:01'),
(46, NULL, 'Muhammed', 'Muhammed Thrissoor', '', 'Thrishoor', NULL, 5, 2, '70257 95579', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:14:12', '2026-03-09 14:01:01'),
(47, NULL, 'Abdu rahman', 'Abdu Rahman PKD', '', 'Palakkad', NULL, 5, 1, '80503 61161', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:15:35', '2026-03-09 12:15:35'),
(48, NULL, 'Arshad Rahman', 'Arshad rahman', '', 'Kollam', NULL, 5, 1, '99958 61100', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:17:27', '2026-03-09 12:17:27'),
(49, NULL, 'Ayan Rahman', 'Ayaan Aluva', '', 'aluva', NULL, 5, 2, '62381 47499', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:18:36', '2026-03-09 14:01:01'),
(50, NULL, 'Sawad', 'Sawad', '', 'Thodupuzha', NULL, 5, 1, '83018 08847', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:18:51', '2026-03-09 14:15:19'),
(51, NULL, 'Salim', 'Salim', '', 'Pukkattupadi', NULL, 5, 2, '97468 48819', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:19:31', '2026-03-09 14:01:01'),
(52, NULL, 'Riyas', 'Riyas', '', 'Kodungalloor', NULL, 5, 2, '81138 35564', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:19:50', '2026-03-09 14:01:01'),
(53, NULL, 'Mihran', 'Mihran', '', 'Aluva', NULL, 5, NULL, '8075 145 559', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:20:05', '2026-03-09 14:15:19'),
(54, NULL, 'Miras', 'Miras', '', 'Kasaragod', NULL, 5, NULL, '90375 30747', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:20:40', '2026-03-09 12:20:40'),
(55, NULL, 'Mahin', 'Mahin MVPT', '', 'Muvattupuzha', NULL, 5, 1, '95391 92540', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:22:43', '2026-03-09 12:22:43'),
(56, NULL, 'Nasrulla', 'Nasrulla', '', 'Thodupuzha', NULL, 5, 2, '90746 13604', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:25:32', '2026-03-09 14:01:01'),
(57, NULL, 'Saeed', 'Saeed ', '', 'Aluva', NULL, 5, 2, '97784 44164', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:26:10', '2026-03-09 14:01:01'),
(58, NULL, 'Anas', 'Anas Pukkattupadi', '', 'Pukkattupadi', NULL, 6, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:28:02', '2026-03-09 12:29:18'),
(59, NULL, 'Abdul Azeez', 'Abdul azeez', '', 'Kothamangalam', NULL, 6, 1, '89430 76486', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:28:47', '2026-03-09 12:29:18'),
(60, NULL, 'Abdulla', 'Abdulla Aluva', '', 'Aluva', NULL, 6, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:29:11', '2026-03-09 12:29:11'),
(61, NULL, 'Hafiz Hasan', 'Hafiz Hasan', '', 'Pattimattam', NULL, 6, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:29:46', '2026-03-09 12:29:46'),
(62, NULL, 'Abdu Rahman', 'Abdu Rahman Vazhakkulam', '', 'Vazhakkulam', NULL, 6, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:30:27', '2026-03-09 12:30:27'),
(63, NULL, 'Imran', 'Imran', '', 'Kasaragod', NULL, 6, 2, '75106 18313', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:31:35', '2026-03-09 12:31:35'),
(64, NULL, 'Noufal', 'noufal Kannur', '', 'Kannur', NULL, 6, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:33:08', '2026-03-09 12:33:08'),
(65, NULL, 'Salman', 'Salman Chilav', '', 'Chilav', NULL, 6, 1, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:33:29', '2026-03-09 12:33:29'),
(66, NULL, 'Swalih', 'Swalih TDPA', '', 'Thodupuzha', NULL, 2, 1, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:35:05', '2026-03-09 12:35:05'),
(67, NULL, 'Yaseen', 'Yaseen Bin Jamal', '', 'Edathala', NULL, 2, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:35:33', '2026-03-09 12:35:33'),
(68, NULL, 'Yaseen', 'Yaseen kanjar', '', 'kanjar', NULL, 2, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 12:36:01', '2026-03-09 12:36:01'),
(69, NULL, 'Usama', 'usama', '', 'Pattimattam', NULL, 5, 1, '88917 46695', NULL, NULL, NULL, NULL, 'active', '2026-03-09 13:57:42', '2026-03-09 13:57:42'),
(70, NULL, 'Hasan', 'Hasan', '', 'Kozhikkod', NULL, 5, 2, '95944 90977', NULL, NULL, NULL, NULL, 'active', '2026-03-09 13:58:31', '2026-03-09 13:58:31'),
(71, NULL, 'Husain', 'Husain', '', 'Kozhikkod', NULL, 5, 2, '95944 90977', NULL, NULL, NULL, NULL, 'active', '2026-03-09 13:58:59', '2026-03-09 13:58:59'),
(72, NULL, 'Muzzammil', 'Muzzammil TDPA', '', 'Thodupuzha', NULL, 5, 1, '80893 68995', NULL, NULL, NULL, NULL, 'active', '2026-03-09 14:01:39', '2026-03-09 14:01:39'),
(73, NULL, 'Swalih', 'Swalih Paravoor', '', 'Paravoor', NULL, 6, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 14:03:05', '2026-03-09 14:03:05'),
(74, NULL, 'Rizwan', 'Rizwan ', '', 'Thodupuzha', NULL, 6, 1, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 14:03:40', '2026-03-09 14:03:40'),
(75, NULL, 'Adhil', 'Adhil Pattimattam', 'علدل', 'Pattimattam', NULL, 3, 2, '', NULL, NULL, NULL, NULL, 'active', '2026-03-09 14:08:21', '2026-03-09 14:08:41'),
(76, NULL, 'Shuraim', 'shuraim', '', 'Kasaragod', NULL, 2, NULL, '88488 67392', NULL, NULL, NULL, NULL, 'active', '2026-03-09 14:12:11', '2026-03-09 14:12:11'),
(77, NULL, 'Favas', 'Favas', '', 'Kollam', NULL, 5, 2, '62829 98572', NULL, NULL, NULL, NULL, 'active', '2026-03-09 14:14:13', '2026-03-09 14:14:13');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `name`, `slug`, `created_at`, `updated_at`) VALUES
(1, 'لغة', '-', '2026-03-10 13:50:23', '2026-03-10 13:50:23'),
(2, 'أدب', '-', '2026-03-10 13:50:28', '2026-03-10 13:50:28'),
(3, 'فقه', '-', '2026-03-10 13:50:46', '2026-03-11 09:22:56'),
(4, 'School', 'school', '2026-03-10 13:51:15', '2026-03-10 13:51:15'),
(5, 'نحو', '-', '2026-03-10 13:51:32', '2026-03-10 13:51:32'),
(6, 'صرف', '-', '2026-03-10 13:51:38', '2026-03-10 13:51:38'),
(7, 'بلاغة', '-', '2026-03-10 13:53:35', '2026-03-10 13:53:35'),
(8, 'عقيدة', '-', '2026-03-10 13:53:50', '2026-03-10 13:53:50'),
(10, 'تفسير', '-', '2026-03-10 13:54:20', '2026-03-10 13:54:20'),
(11, 'حديث', '-', '2026-03-10 13:54:44', '2026-03-10 13:54:44'),
(12, 'أصول الفقه', '-', '2026-03-10 14:03:28', '2026-03-11 09:22:27'),
(14, 'الواصلة', '-', '2026-03-10 14:22:33', '2026-03-10 14:22:33'),
(15, 'القراءة', '-', '2026-03-10 14:24:50', '2026-03-10 14:24:50'),
(16, 'السيرة اليبوية', '-', '2026-03-10 14:31:38', '2026-03-10 14:31:38'),
(17, 'منطق', '-', '2026-03-11 06:29:13', '2026-03-11 06:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `full_name` varchar(200) DEFAULT NULL,
  `place` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `specialisation` varchar(255) DEFAULT NULL,
  `status` enum('active','left','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`id`, `user_id`, `full_name`, `place`, `phone`, `email`, `specialisation`, `status`, `created_at`, `updated_at`) VALUES
(6, 6, 'MHX', 'Kottayam', '9037861023', 'mhx.xyz@gmail.com', NULL, 'active', '2026-03-15 12:05:11', '2026-03-16 09:03:53');

-- --------------------------------------------------------

--
-- Table structure for table `teacher_authorities`
--

CREATE TABLE `teacher_authorities` (
  `id` int UNSIGNED NOT NULL,
  `teacher_id` int UNSIGNED NOT NULL,
  `authority_id` int UNSIGNED NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_registrations`
--

CREATE TABLE `teacher_registrations` (
  `id` int NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `place` varchar(150) DEFAULT NULL,
  `specialisation` varchar(150) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_specialisations`
--

CREATE TABLE `teacher_specialisations` (
  `teacher_id` int UNSIGNED NOT NULL,
  `specialisation_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `username` varchar(80) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `phone_verified_at` datetime DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(200) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `phone`, `phone_verified_at`, `email`, `email_verified_at`, `password`, `full_name`, `status`, `last_login`, `created_at`, `updated_at`, `deleted_at`, `profile_photo`) VALUES
(1, 'admin', '0000000000', NULL, 'admin@kauzariyya.com', NULL, '$2y$10$l2upgkZZP.L.7ZrlADtfcenaE7oH.zbkCVjRS0tkmEl6oqiLqxtlq', 'Administrator', 'active', '2026-05-27 08:51:02', '2026-03-08 10:12:28', '2026-05-27 03:21:02', NULL, 'user_1_1773225684.png'),
(6, 'MHX', '9037861023', NULL, NULL, NULL, '$2y$10$5IYYOVTG1VFh.kMwTXhmZeWMpa1Ih2hI8udavzQ4TvPl8MNBBJDja', 'MHX', 'active', NULL, '2026-03-16 09:03:53', '2026-03-16 09:03:53', NULL, NULL),
(7, 'Hudaifa', '9037861024', '2026-05-26 16:35:45', '', NULL, '$2y$10$3uPXoRTw1ZM4y2M7iiheMuW8qpQr/kbOu9MleoThbVGc/4e6uhzv6', 'Hudaifa', 'active', '2026-05-26 16:32:59', '2026-03-16 10:33:37', '2026-05-26 11:05:45', NULL, 'user_7_1773660968.png');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int UNSIGNED NOT NULL,
  `role_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 1),
(6, 2),
(7, 3);

--
-- Dumping data for table `authorities`
--

INSERT INTO `authorities` (`id`, `name`, `slug`, `description`) VALUES
(1, 'TV Controller', 'control-tv', 'Controls the TV scoreboard display and feeds'),
(2, 'Score Uploader', 'upload-scores', 'Uploads and updates judge score cards'),
(3, 'Entries Assigner', 'assign-entries', 'Assigns students to musabaqa/competition entries'),
(4, 'Members Info Viewer', 'members-info', 'Access to member ID cards, chest numbers, and details')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `user_id` int UNSIGNED NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `event_id` int UNSIGNED NOT NULL,
  `category` varchar(50) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'Individual',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `programs_slug_unique` (`slug`),
  KEY `fk_programs_event_idx` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `program_entries`
--

CREATE TABLE `program_entries` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` int UNSIGNED NOT NULL,
  `student_id` int UNSIGNED NOT NULL,
  `chest_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_program` (`program_id`,`student_id`),
  KEY `fk_pe_student_idx` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scores`
--

CREATE TABLE `scores` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_id` int UNSIGNED NOT NULL,
  `judge_number` int NOT NULL,
  `marks` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_entry_judge` (`entry_id`,`judge_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tv_settings`
--

CREATE TABLE `tv_settings` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `active_program_id` int UNSIGNED DEFAULT NULL,
  `display_type` varchar(50) NOT NULL DEFAULT 'scoreboard',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tv_program_idx` (`active_program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `authorities`
--
ALTER TABLE `authorities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_authority_slug` (`slug`);

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_books_class` (`class_id`),
  ADD KEY `fk_books_subject` (`subject_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `fk_class_type` (`class_type_id`);

--
-- Indexes for table `class_types`
--
ALTER TABLE `class_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maddhabs`
--
ALTER TABLE `maddhabs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `slug` (`slug`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `slug` (`slug`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`user_id`,`permission_id`),
  ADD KEY `fk_up_perm` (`permission_id`);

--
-- Indexes for table `user_authorities`
--
ALTER TABLE `user_authorities`
  ADD PRIMARY KEY (`user_id`,`authority_id`),
  ADD KEY `fk_ua_auth` (`authority_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `specialisations`
--
ALTER TABLE `specialisations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_slug` (`slug`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_students_maddhab` (`maddhab_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_subject_name` (`name`),
  ADD KEY `idx_subject_slug` (`slug`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `email` (`email`);

--
-- Indexes for table `teacher_authorities`
--
ALTER TABLE `teacher_authorities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_teacher_authority` (`teacher_id`,`authority_id`),
  ADD KEY `idx_teacher` (`teacher_id`),
  ADD KEY `idx_authority` (`authority_id`);

--
-- Indexes for table `teacher_registrations`
--
ALTER TABLE `teacher_registrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teacher_specialisations`
--
ALTER TABLE `teacher_specialisations`
  ADD PRIMARY KEY (`teacher_id`,`specialisation_id`),
  ADD KEY `fk_ts_spec` (`specialisation_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`),
  ADD KEY `phone` (`phone`),
  ADD KEY `email` (`email`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_ur_role` (`role_id`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`user_id`,`key`),
  ADD KEY `idx_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `authorities`
--
ALTER TABLE `authorities`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `class_types`
--
ALTER TABLE `class_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `maddhabs`
--
ALTER TABLE `maddhabs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `specialisations`
--
ALTER TABLE `specialisations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `teacher_authorities`
--
ALTER TABLE `teacher_authorities`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_registrations`
--
ALTER TABLE `teacher_registrations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `books`
--
ALTER TABLE `books`
  ADD CONSTRAINT `fk_books_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_books_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_class_type` FOREIGN KEY (`class_type_id`) REFERENCES `class_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `fk_up_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_authorities`
--
ALTER TABLE `user_authorities`
  ADD CONSTRAINT `fk_ua_auth` FOREIGN KEY (`authority_id`) REFERENCES `authorities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ua_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_maddhab` FOREIGN KEY (`maddhab_id`) REFERENCES `maddhabs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `teacher_authorities`
--
ALTER TABLE `teacher_authorities`
  ADD CONSTRAINT `fk_ta_authority` FOREIGN KEY (`authority_id`) REFERENCES `authorities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ta_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `teacher_specialisations`
--
ALTER TABLE `teacher_specialisations`
  ADD CONSTRAINT `fk_ts_spec` FOREIGN KEY (`specialisation_id`) REFERENCES `specialisations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `fk_roles_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `fk_programs_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `program_entries`
--
ALTER TABLE `program_entries`
  ADD CONSTRAINT `fk_pe_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pe_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `scores`
--
ALTER TABLE `scores`
  ADD CONSTRAINT `fk_scores_entry` FOREIGN KEY (`entry_id`) REFERENCES `program_entries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tv_settings`
--
ALTER TABLE `tv_settings`
  ADD CONSTRAINT `fk_tv_program` FOREIGN KEY (`active_program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
