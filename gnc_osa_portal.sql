-- ------------------------------------------------------------
-- NOTE (Completed elections): table `elections` now has `is_completed`.
-- Fresh import: nothing else to do.
-- Existing database (already imported before): run this ONE line only:
--   ALTER TABLE elections ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_hold;
-- ------------------------------------------------------------

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 29, 2026 at 12:31 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gnc_osa_portal`
--

-- --------------------------------------------------------

--
-- Table structure for table `candidates`
--

CREATE TABLE `candidates` (
  `id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `party_list_id` int(11) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `credentials` text DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `is_approved` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `candidates`
--

INSERT INTO `candidates` (`id`, `election_id`, `position_id`, `student_id`, `party_list_id`, `bio`, `credentials`, `photo`, `is_approved`, `created_at`) VALUES
(10, 14, 31, 3, NULL, '', '', NULL, 1, '2026-06-24 19:47:04'),
(12, 16, 35, 3, NULL, '', NULL, NULL, 1, '2026-06-25 01:48:27');

-- --------------------------------------------------------

--
-- Table structure for table `complaints`
--

CREATE TABLE `complaints` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `category` enum('Department-related Issue','Student organization officer concern','Mismanagement of organization duties/resources','Unfair treatment / lack of transparency by officers','Others') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `status` enum('New','In Review','Resolved','Rejected','Closed') DEFAULT 'New',
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_to_name` varchar(150) DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `admin_attachment` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `complaints`
--

INSERT INTO `complaints` (`id`, `student_id`, `category`, `title`, `message`, `attachment`, `is_anonymous`, `status`, `assigned_to`, `assigned_to_name`, `admin_remarks`, `admin_attachment`, `created_at`, `updated_at`, `is_archived`) VALUES
(20, 3, 'Others', 'teacher', 'asdasda', NULL, 0, 'Resolved', NULL, NULL, '', NULL, '2026-06-21 12:48:16', '2026-09-21 21:49:12', 1),
(24, 3, 'Others', '123', '123', NULL, 0, 'New', NULL, NULL, NULL, NULL, '2026-06-24 21:27:49', '2026-06-24 21:32:58', 0),
(25, 3, 'Student organization officer concern', '123123', '12312', NULL, 0, 'New', NULL, NULL, NULL, NULL, '2026-06-24 21:37:44', '2026-06-24 21:37:44', 0),
(26, 3, 'Student organization officer concern', 'Test', '123', NULL, 0, 'New', NULL, NULL, NULL, NULL, '2026-06-28 11:30:46', '2026-06-28 11:30:46', 0),
(27, 3, 'Student organization officer concern', 'Test', '123', NULL, 0, 'New', NULL, NULL, NULL, NULL, '2026-06-28 11:36:52', '2026-06-28 11:36:52', 0),
(32, 3, 'Others', 'Rooms', 'dami nag iiwan ng kalat sa mga rooms', NULL, 0, 'New', 2, NULL, NULL, NULL, '2026-07-12 16:03:15', '2026-07-12 16:04:44', 0);

-- --------------------------------------------------------

--
-- Table structure for table `complaint_reviews`
--

CREATE TABLE `complaint_reviews` (
  `id` int(11) NOT NULL,
  `complaint_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `reaction` varchar(10) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `complaint_reviews`
--

INSERT INTO `complaint_reviews` (`id`, `complaint_id`, `student_id`, `reaction`, `comment`, `created_at`) VALUES
(1, 20, 3, '😢', NULL, '2026-06-21 13:06:40');

-- --------------------------------------------------------

--
-- Table structure for table `elections`
--

CREATE TABLE `elections` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `course` varchar(100) NOT NULL DEFAULT '',
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `is_hold` tinyint(1) NOT NULL DEFAULT 0,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `show_results` tinyint(1) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `elections`
--

INSERT INTO `elections` (`id`, `title`, `description`, `course`, `start_date`, `end_date`, `is_active`, `is_hold`, `show_results`, `created_by`, `created_at`) VALUES
(14, 'BSIT 2026- 2027', '', '', '2026-06-24 19:46:00', '2026-06-25 19:46:00', 1, 0, 0, 1, '2026-06-24 19:46:54'),
(16, '2026-2027', '', 'BSIT', '2026-06-25 01:43:00', '2026-06-26 01:43:00', 1, 0, 0, 1, '2026-06-25 01:43:39'),
(17, '2016-2027', '', 'BSPharm', '2026-06-25 02:54:00', '2026-06-26 02:54:00', 1, 0, 0, 1, '2026-06-25 02:55:10');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `event_time` time DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `fb_post_id` varchar(64) DEFAULT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_type` enum('admin','staff') NOT NULL,
  `created_by_id` int(11) NOT NULL,
  `created_by_name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `title`, `description`, `event_date`, `end_date`, `event_time`, `location`, `image`, `fb_post_id`, `is_cancelled`, `created_by_type`, `created_by_id`, `created_by_name`, `created_at`, `updated_at`) VALUES
(1, 'General Assembly', 'Orientation for all students regarding OSA policies.', '2026-10-05', NULL, '09:00:00', 'GNC Gymnasium', NULL, NULL, 0, 'admin', 1, 'OSA Admin', '2026-09-21 13:48:04', '2026-09-21 13:48:04'),
(2, 'Intramurals 2026', 'Annual sports fest for all courses.', '2026-10-20', '2026-10-22', '08:00:00', 'GNC Athletic Field', NULL, NULL, 0, 'staff', 2, 'Jasper Celestial', '2026-09-21 13:48:04', '2026-09-21 13:48:04');

-- --------------------------------------------------------

--
-- Table structure for table `fb_posts`
--

CREATE TABLE `fb_posts` (
  `id` int(11) NOT NULL,
  `fb_post_id` varchar(64) NOT NULL,
  `message` text DEFAULT NULL,
  `full_picture` varchar(500) DEFAULT NULL,
  `permalink_url` varchar(500) DEFAULT NULL,
  `created_time` datetime NOT NULL,
  `fetched_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fb_posts`
--

INSERT INTO `fb_posts` (`id`, `fb_post_id`, `message`, `full_picture`, `permalink_url`, `created_time`, `fetched_at`) VALUES
(1, '1428529310339612_122101425189462650', 'Orange knows something… but he\'s not telling Apple. 👀\nWho are “they”?\nTo be continued… \n\n#FRUITFALL  #Mystery #Thriller #AIFilmmaking  #reelsviralシ', 'fb_cache/fbpost_1428529310339612_122101425189462650.jpg', 'https://www.facebook.com/reel/1039622582215321/', '2026-09-04 16:33:24', '2026-09-29 05:48:39'),
(2, '1428529310339612_122101168689462650', 'The computer knew his name… but why? 🍎 Who is Subject 001? The mystery continues.\n#fruitfall #mystery  #thriller  #reelsviral', 'fb_cache/fbpost_1428529310339612_122101168689462650.jpg', 'https://www.facebook.com/reel/1354422200187134/', '2026-09-04 07:19:50', '2026-09-29 05:48:39'),
(3, '1428529310339612_122101159287462650', 'He found a photo of himself… from 1896. But how is that possible? 🍎 The mystery begins. Follow the story for episode 2.\n\n#fruitfall #aishorts  #aistory   #reelsvideoシ', 'fb_cache/fbpost_1428529310339612_122101159287462650.jpg', 'https://www.facebook.com/reel/1805650637446534/', '2026-09-04 07:10:26', '2026-09-29 05:48:40');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `student_id`, `message`, `link`, `is_read`, `created_at`) VALUES
(42, 3, 'Update on your complaint #7: status is now In Review.', 'view-complaint.php?id=7', 1, '2026-06-07 11:41:31'),
(43, 3, 'Update on your complaint #7: status is now Resolved.', 'view-complaint.php?id=7', 1, '2026-06-07 11:41:48'),
(44, 3, 'Update on your complaint #7: status is now Rejected.', 'view-complaint.php?id=7', 1, '2026-06-07 11:42:04'),
(45, 3, 'Update on your complaint #7: status is now New.', 'view-complaint.php?id=7', 1, '2026-06-07 11:42:17'),
(46, 3, 'Update on your complaint #7: status is now Resolved.', 'view-complaint.php?id=7', 1, '2026-06-07 12:09:20'),
(47, 3, 'Update on your complaint #7: status is now Rejected.', 'view-complaint.php?id=7', 1, '2026-06-07 12:13:40'),
(48, 3, 'Update on your complaint #20: status is now Closed.', 'view-complaint.php?id=20', 1, '2026-06-21 12:54:21'),
(49, 3, 'Update on your complaint #20: status is now New.', 'view-complaint.php?id=20', 1, '2026-06-21 12:54:42'),
(50, 3, 'Update on your complaint #20: status is now Resolved.', 'view-complaint.php?id=20', 1, '2026-06-21 13:06:16'),
(51, 3, 'Your vote has been recorded in BSIT 2026- 2027. Thank you for participating!', 'voting.php', 1, '2026-06-24 19:48:13'),
(65, 3, 'Your account has been re-enabled. You can now log in.', '../index.php', 0, '2026-09-28 16:53:55'),
(66, 3, 'Your account has been re-enabled. You can now log in.', '../index.php', 0, '2026-09-28 16:54:19'),
(67, 102, 'Your account has been approved! You can now log in.', '../index.php', 1, '2026-09-28 17:05:15'),
(68, 101, 'Your account has been approved! You can now log in.', '../index.php', 0, '2026-09-29 06:01:48'),
(69, 103, 'Your account has been approved! You can now log in.', '../index.php', 0, '2026-09-29 06:03:40'),
(70, 104, 'Your account has been approved! You can now log in.', '../index.php', 0, '2026-09-29 06:12:49'),
(71, 105, 'Your account has been approved! You can now log in.', '../index.php', 0, '2026-09-29 06:19:21');

-- --------------------------------------------------------

--
-- Table structure for table `osa_staff`
--

CREATE TABLE `osa_staff` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `osa_staff`
--

INSERT INTO `osa_staff` (`id`, `username`, `first_name`, `last_name`, `email`, `role`, `password`, `is_active`, `created_at`) VALUES
(1, 'osa_admin', 'OSA', 'Admin', 'jaspercelestial1@gmail.com', 'admin', '$2y$10$j/4auo16Lo20PUsvaBRyeO2VdWnfFiqOBUBDvkh4KA/lDLH08FpZS', 1, '2026-06-06 13:24:27'),
(2, 'jasper', 'Jasper', 'Celestial', '', 'staff', '$2y$10$AsMckhUeUCZ8VIJ6zp6T4ujomz3Zzj.v2OpMUKLwhTBG44zk./o.2', 1, '2026-06-06 20:17:29');

-- --------------------------------------------------------

--
-- Table structure for table `party_lists`
--

CREATE TABLE `party_lists` (
  `id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `platform` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6B1A2A',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `max_votes` tinyint(4) DEFAULT 1,
  `sort_order` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`id`, `election_id`, `title`, `max_votes`, `sort_order`) VALUES
(31, 14, 'President', 1, 0),
(32, 14, 'Vice-President', 1, 1),
(35, 16, 'President', 1, 0),
(36, 16, 'Vice-President', 1, 1),
(37, 17, 'President', 1, 0),
(38, 17, 'Vice-President', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `staff_notifications`
--

CREATE TABLE `staff_notifications` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` tinyint(4) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `cor_file` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `approved_at` datetime DEFAULT NULL,
  `disabled_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `student_id`, `first_name`, `last_name`, `email`, `course`, `year_level`, `section`, `cor_file`, `password`, `is_active`, `approved_at`, `disabled_at`, `created_at`, `must_change_password`) VALUES
(3, '20195537', 'Jasper', 'Celestial', 'jaspercelestial.gnc@gmail.com', 'BSIT', NULL, NULL, NULL, '$2y$10$CgmX0Fcu7FUdJ4HGt7atc.HtvJwdvzSvUoKBzzE9FEwJEY9Xg5a0O', 1, '2026-09-28 16:53:55', NULL, '2026-06-06 18:37:54', 0),
(101, '20192019', 'Jasper', 'Dela Cruz', 'jaspercelestial1@gmail.com', 'BSIT', NULL, NULL, 'cor_6ab26f06b7470.jpg', '$2y$10$oYkaGP1VvV0mW2PLbBo0DuRhyg2/wAXbARtpp/FlqqxHp/YYVstB.', 1, '2026-09-29 06:01:48', NULL, '2026-09-22 20:07:41', 0),
(102, '20191241', 'Jayce', 'lesis', 'jaycelesis.gnc@gmail.com', 'BSIT', NULL, NULL, 'cor_6aba2d876c27a.jpeg', '$2y$10$47gik4MQZDdKwNnhgkyZ5OIrDhDo4QpS.tGSw.YV0Px/BxNmJHU7i', 1, '2026-09-28 17:05:15', NULL, '2026-09-28 17:04:56', 0),
(103, '201912412', 'Jeisu', 'Lesis', 'treeepzyyt12@gmail.com', 'BSIT', NULL, NULL, 'cor_6abae40b35e77.png', '$2y$10$BduCykYbnC2ZL.4jVq5VFutfYxb1J2xtHW6mmqz3v0Or6IsIoNmrq', 1, '2026-09-29 06:03:40', NULL, '2026-09-29 06:03:17', 0),
(104, '20198563', 'Kortopi', 'Dela Puente', 'jaycelesis.mexico@gmail.com', 'BSIT', NULL, NULL, 'cor_6abae4e1a8caa.png', '$2y$10$glctvCDqcYMlq5Y1Qr0rzuwr6H9K/Pnp/DPkm7BQPHgsQ87rbCtk6', 1, '2026-09-29 06:12:49', NULL, '2026-09-29 06:11:38', 0),
(105, '2019512', 'Antonio', 'Luna', 'jeisulesis13@gmail.com', 'BSED', NULL, NULL, 'cor_6abae6c3257b8.png', '$2y$10$DfJp30eKAggJxEGpW.iB3.HnIl0iiDpeon8SzjS4MxqZBmq7Ke7Ey', 1, '2026-09-29 06:19:21', NULL, '2026-09-29 06:14:44', 0);

-- --------------------------------------------------------

--
-- Table structure for table `votes`
--

CREATE TABLE `votes` (
  `id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `candidate_id` int(11) DEFAULT NULL,
  `is_abstain` tinyint(1) NOT NULL DEFAULT 0,
  `voted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `votes`
--

INSERT INTO `votes` (`id`, `election_id`, `position_id`, `student_id`, `candidate_id`, `is_abstain`, `voted_at`) VALUES
(13, 14, 31, 3, NULL, 1, '2026-06-24 19:48:13'),
(14, 14, 32, 3, NULL, 1, '2026-06-24 19:48:13'),
(17, 16, 35, 3, 12, 0, '2026-06-25 01:49:02'),
(18, 16, 36, 3, 13, 0, '2026-06-25 01:49:02');

-- --------------------------------------------------------

--
-- Table structure for table `voting_vouchers`
--

CREATE TABLE `voting_vouchers` (
  `id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `code` varchar(14) NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `generated_by` int(11) NOT NULL,
  `generated_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `voting_vouchers`
--

INSERT INTO `voting_vouchers` (`id`, `election_id`, `student_id`, `code`, `is_used`, `used_at`, `generated_by`, `generated_at`) VALUES
(24, 16, 3, 'AY4S-XBT2-9C', 0, NULL, 1, '2026-06-25 02:29:38');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `election_id` (`election_id`),
  ADD KEY `position_id` (`position_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `party_list_id` (`party_list_id`);

--
-- Indexes for table `complaints`
--
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_archived` (`is_archived`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `complaint_reviews`
--
ALTER TABLE `complaint_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_review` (`complaint_id`,`student_id`);

--
-- Indexes for table `elections`
--
ALTER TABLE `elections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_date` (`event_date`);

--
-- Indexes for table `fb_posts`
--
ALTER TABLE `fb_posts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fb_post_id` (`fb_post_id`),
  ADD KEY `created_time` (`created_time`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `osa_staff`
--
ALTER TABLE `osa_staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `party_lists`
--
ALTER TABLE `party_lists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `election_id` (`election_id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `election_id` (`election_id`);

--
-- Indexes for table `staff_notifications`
--
ALTER TABLE `staff_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- Indexes for table `votes`
--
ALTER TABLE `votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_vote` (`election_id`,`position_id`,`student_id`),
  ADD KEY `position_id` (`position_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `candidate_id` (`candidate_id`);

--
-- Indexes for table `voting_vouchers`
--
ALTER TABLE `voting_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_code` (`code`),
  ADD UNIQUE KEY `unique_student_election` (`election_id`,`student_id`),
  ADD KEY `election_id` (`election_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `generated_by` (`generated_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `complaint_reviews`
--
ALTER TABLE `complaint_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `elections`
--
ALTER TABLE `elections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `fb_posts`
--
ALTER TABLE `fb_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `osa_staff`
--
ALTER TABLE `osa_staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `party_lists`
--
ALTER TABLE `party_lists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `staff_notifications`
--
ALTER TABLE `staff_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=106;

--
-- AUTO_INCREMENT for table `votes`
--
ALTER TABLE `votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `voting_vouchers`
--
ALTER TABLE `voting_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `candidates`
--
ALTER TABLE `candidates`
  ADD CONSTRAINT `candidates_ibfk_1` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `candidates_ibfk_2` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `candidates_ibfk_3` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `candidates_ibfk_4` FOREIGN KEY (`party_list_id`) REFERENCES `party_lists` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `complaints`
--
ALTER TABLE `complaints`
  ADD CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `complaints_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `osa_staff` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `elections`
--
ALTER TABLE `elections`
  ADD CONSTRAINT `elections_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `osa_staff` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `party_lists`
--
ALTER TABLE `party_lists`
  ADD CONSTRAINT `party_lists_ibfk_1` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `positions`
--
ALTER TABLE `positions`
  ADD CONSTRAINT `positions_ibfk_1` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `votes`
--
ALTER TABLE `votes`
  ADD CONSTRAINT `votes_ibfk_1` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `votes_ibfk_2` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `votes_ibfk_3` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `voting_vouchers`
--
ALTER TABLE `voting_vouchers`
  ADD CONSTRAINT `vv_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vv_staff` FOREIGN KEY (`generated_by`) REFERENCES `osa_staff` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vv_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
