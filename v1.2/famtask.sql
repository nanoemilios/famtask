-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mariadb:3306
-- Generation Time: Mar 23, 2026 at 07:47 PM
-- Server version: 10.11.16-MariaDB-ubu2204
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `famtask`
--

-- --------------------------------------------------------

--
-- Table structure for table `famtask_families`
--

CREATE TABLE `famtask_families` (
  `code` varchar(10) NOT NULL,
  `name` varchar(80) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `famtask_families`
--

INSERT INTO `famtask_families` (`code`, `name`, `created_at`) VALUES
('66NPH3', 'Costa', '2026-03-23 19:44:13');

-- --------------------------------------------------------

--
-- Table structure for table `famtask_stats`
--

CREATE TABLE `famtask_stats` (
  `id` int(11) NOT NULL,
  `day` date NOT NULL,
  `child_id` varchar(20) NOT NULL,
  `child_name` varchar(80) NOT NULL,
  `event_type` enum('task_done','task_missed','media_used') NOT NULL,
  `ref_id` varchar(80) NOT NULL,
  `ref_label` varchar(120) DEFAULT NULL,
  `minutes` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `famtask_families`
--
ALTER TABLE `famtask_families`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `famtask_stats`
--
ALTER TABLE `famtask_stats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_day` (`day`),
  ADD KEY `idx_child` (`child_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `famtask_stats`
--
ALTER TABLE `famtask_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
