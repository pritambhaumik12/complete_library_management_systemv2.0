-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 28, 2025 at 09:27 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lms55_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_admin`
--

CREATE TABLE `tbl_admin` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `library_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_admin`
--

INSERT INTO `tbl_admin` (`admin_id`, `username`, `password`, `full_name`, `is_super_admin`, `created_at`, `library_id`) VALUES
(1, 'admin', 'password', 'System Administrator', 1, '2025-11-23 07:45:42', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_archived_fines`
--

CREATE TABLE `tbl_archived_fines` (
  `fine_id` int(11) NOT NULL,
  `fine_uid` varchar(50) NOT NULL,
  `circulation_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `fine_type` varchar(50) DEFAULT 'Late Return',
  `fine_amount` decimal(10,2) NOT NULL,
  `fine_date` date NOT NULL,
  `is_outstanding` tinyint(1) NOT NULL DEFAULT 0,
  `pay_later_due_date` date DEFAULT NULL,
  `payment_status` enum('Pending','Paid') NOT NULL DEFAULT 'Pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `paid_on` timestamp NULL DEFAULT NULL,
  `collected_by_admin_id` int(11) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_by` int(11) DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_books`
--

CREATE TABLE `tbl_books` (
  `book_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `edition` varchar(50) DEFAULT NULL,
  `publication` varchar(255) DEFAULT NULL,
  `isbn` varchar(20) DEFAULT NULL,
  `category` varchar(100) NOT NULL,
  `total_quantity` int(11) NOT NULL DEFAULT 0,
  `available_quantity` int(11) NOT NULL DEFAULT 0,
  `shelf_location` varchar(50) NOT NULL,
  `soft_copy_path` varchar(255) DEFAULT NULL,
  `is_online_available` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `library_id` int(11) DEFAULT NULL,
  `content_type_id` int(11) DEFAULT NULL,
  `security_control` enum('Yes','No') DEFAULT 'No',
  `is_downloadable` tinyint(1) NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cover_image` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_book_copies`
--

CREATE TABLE `tbl_book_copies` (
  `copy_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `book_uid` varchar(50) NOT NULL,
  `status` enum('Available','Issued','Reserved','Lost') NOT NULL DEFAULT 'Available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_circulation`
--

CREATE TABLE `tbl_circulation` (
  `circulation_id` int(11) NOT NULL,
  `copy_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `status` enum('Issued','Returned','Overdue','Lost') NOT NULL DEFAULT 'Issued',
  `issued_by_admin_id` int(11) NOT NULL,
  `returned_by_admin_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_content_types`
--

CREATE TABLE `tbl_content_types` (
  `content_type_id` int(11) NOT NULL,
  `type_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_content_types`
--

INSERT INTO `tbl_content_types` (`content_type_id`, `type_name`) VALUES
(5, 'Book'),
(1, 'Journal'),
(2, 'Magazine'),
(4, 'Novel'),
(3, 'Research Paper');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_favorites`
--

CREATE TABLE `tbl_favorites` (
  `favorite_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_fines`
--

CREATE TABLE `tbl_fines` (
  `fine_id` int(11) NOT NULL,
  `fine_uid` varchar(50) NOT NULL,
  `circulation_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `fine_type` varchar(50) DEFAULT 'Late Return',
  `fine_amount` decimal(10,2) NOT NULL,
  `fine_date` date NOT NULL,
  `is_outstanding` tinyint(1) NOT NULL DEFAULT 0,
  `pay_later_due_date` date DEFAULT NULL,
  `payment_status` enum('Pending','Paid') NOT NULL DEFAULT 'Pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `paid_on` timestamp NULL DEFAULT NULL,
  `collected_by_admin_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_learnings`
--

CREATE TABLE `tbl_learnings` (
  `learning_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `read_date` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_libraries`
--

CREATE TABLE `tbl_libraries` (
  `library_id` int(11) NOT NULL,
  `library_name` varchar(255) NOT NULL,
  `library_initials` varchar(50) NOT NULL,
  `library_location` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_members`
--

CREATE TABLE `tbl_members` (
  `member_id` int(11) NOT NULL,
  `member_uid` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `department` varchar(100) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `screenshot_violations` int(11) DEFAULT 0,
  `reset_otp` varchar(10) DEFAULT NULL,
  `reset_otp_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_reservations`
--

CREATE TABLE `tbl_reservations` (
  `reservation_id` int(11) NOT NULL,
  `reservation_uid` varchar(50) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `book_base_uid` varchar(50) DEFAULT NULL,
  `reservation_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Accepted','Rejected','Fulfilled','Cancelled') NOT NULL DEFAULT 'Pending',
  `cancelled_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_settings`
--

CREATE TABLE `tbl_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_settings`
--

INSERT INTO `tbl_settings` (`setting_key`, `setting_value`, `description`) VALUES
('currency_symbol', '₹', 'Currency symbol for fines'),
('fine_per_day', '5', 'Fine amount per day for overdue books'),
('institution_initials', 'INSTITUTION INITIAL', 'Initials of the Institution'),
('institution_logo', 'uploads/logos/logo_1764247761.jpeg', 'Path to the institution logo'),
('institution_name', 'Institution name', 'The name of the institution'),
('late_pay_fine_rate', '10', 'Percentage increase for late fine payment'),
('library_initials', 'CLIB-LMS', 'Initials of the Library'),
('library_name', 'Powered by Librario', 'Name of Product'),
('max_borrow_days', '1', 'Maximum number of days a book can be borrowed'),
('max_borrow_limit', '3', 'Maximum number of books a member can borrow at one time'),
('max_pay_later_days', '7', 'Days allowed to pay fine later'),
('online_password_reset', '1', 'Enable/Disable Member Password Reset via OTP'),
('reminder_days', '1,3,7', 'Days before due date to send reminder emails (comma separated)');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_system_alerts`
--

CREATE TABLE `tbl_system_alerts` (
  `alert_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) DEFAULT NULL,
  `alert_type` varchar(50) NOT NULL DEFAULT 'Security Breach',
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_admin`
--
ALTER TABLE `tbl_admin`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_admin_library` (`library_id`);

--
-- Indexes for table `tbl_archived_fines`
--
ALTER TABLE `tbl_archived_fines`
  ADD PRIMARY KEY (`fine_id`),
  ADD KEY `circulation_id` (`circulation_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `collected_by_admin_id` (`collected_by_admin_id`);

--
-- Indexes for table `tbl_books`
--
ALTER TABLE `tbl_books`
  ADD PRIMARY KEY (`book_id`),
  ADD KEY `fk_book_library` (`library_id`),
  ADD KEY `fk_book_content_type` (`content_type_id`);

--
-- Indexes for table `tbl_book_copies`
--
ALTER TABLE `tbl_book_copies`
  ADD PRIMARY KEY (`copy_id`),
  ADD UNIQUE KEY `book_uid` (`book_uid`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `tbl_circulation`
--
ALTER TABLE `tbl_circulation`
  ADD PRIMARY KEY (`circulation_id`),
  ADD KEY `copy_id` (`copy_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `issued_by_admin_id` (`issued_by_admin_id`);

--
-- Indexes for table `tbl_content_types`
--
ALTER TABLE `tbl_content_types`
  ADD PRIMARY KEY (`content_type_id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `tbl_favorites`
--
ALTER TABLE `tbl_favorites`
  ADD PRIMARY KEY (`favorite_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `tbl_fines`
--
ALTER TABLE `tbl_fines`
  ADD PRIMARY KEY (`fine_id`),
  ADD KEY `circulation_id` (`circulation_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `collected_by_admin_id` (`collected_by_admin_id`);

--
-- Indexes for table `tbl_learnings`
--
ALTER TABLE `tbl_learnings`
  ADD PRIMARY KEY (`learning_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `tbl_libraries`
--
ALTER TABLE `tbl_libraries`
  ADD PRIMARY KEY (`library_id`),
  ADD UNIQUE KEY `library_initials` (`library_initials`);

--
-- Indexes for table `tbl_members`
--
ALTER TABLE `tbl_members`
  ADD PRIMARY KEY (`member_id`),
  ADD UNIQUE KEY `member_uid` (`member_uid`);

--
-- Indexes for table `tbl_reservations`
--
ALTER TABLE `tbl_reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD UNIQUE KEY `reservation_uid` (`reservation_uid`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `tbl_settings`
--
ALTER TABLE `tbl_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tbl_system_alerts`
--
ALTER TABLE `tbl_system_alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD KEY `member_id` (`member_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_admin`
--
ALTER TABLE `tbl_admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_archived_fines`
--
ALTER TABLE `tbl_archived_fines`
  MODIFY `fine_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `tbl_books`
--
ALTER TABLE `tbl_books`
  MODIFY `book_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `tbl_book_copies`
--
ALTER TABLE `tbl_book_copies`
  MODIFY `copy_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=160;

--
-- AUTO_INCREMENT for table `tbl_circulation`
--
ALTER TABLE `tbl_circulation`
  MODIFY `circulation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `tbl_content_types`
--
ALTER TABLE `tbl_content_types`
  MODIFY `content_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_favorites`
--
ALTER TABLE `tbl_favorites`
  MODIFY `favorite_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `tbl_fines`
--
ALTER TABLE `tbl_fines`
  MODIFY `fine_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `tbl_learnings`
--
ALTER TABLE `tbl_learnings`
  MODIFY `learning_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tbl_libraries`
--
ALTER TABLE `tbl_libraries`
  MODIFY `library_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_members`
--
ALTER TABLE `tbl_members`
  MODIFY `member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `tbl_reservations`
--
ALTER TABLE `tbl_reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `tbl_system_alerts`
--
ALTER TABLE `tbl_system_alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_admin`
--
ALTER TABLE `tbl_admin`
  ADD CONSTRAINT `fk_admin_library` FOREIGN KEY (`library_id`) REFERENCES `tbl_libraries` (`library_id`) ON DELETE SET NULL;

--
-- Constraints for table `tbl_books`
--
ALTER TABLE `tbl_books`
  ADD CONSTRAINT `fk_book_content_type` FOREIGN KEY (`content_type_id`) REFERENCES `tbl_content_types` (`content_type_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_book_library` FOREIGN KEY (`library_id`) REFERENCES `tbl_libraries` (`library_id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_book_copies`
--
ALTER TABLE `tbl_book_copies`
  ADD CONSTRAINT `tbl_book_copies_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `tbl_books` (`book_id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_circulation`
--
ALTER TABLE `tbl_circulation`
  ADD CONSTRAINT `tbl_circulation_ibfk_1` FOREIGN KEY (`copy_id`) REFERENCES `tbl_book_copies` (`copy_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_circulation_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `tbl_members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_circulation_ibfk_3` FOREIGN KEY (`issued_by_admin_id`) REFERENCES `tbl_admin` (`admin_id`);

--
-- Constraints for table `tbl_favorites`
--
ALTER TABLE `tbl_favorites`
  ADD CONSTRAINT `tbl_favorites_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `tbl_members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_favorites_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `tbl_books` (`book_id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_fines`
--
ALTER TABLE `tbl_fines`
  ADD CONSTRAINT `tbl_fines_ibfk_1` FOREIGN KEY (`circulation_id`) REFERENCES `tbl_circulation` (`circulation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_fines_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `tbl_members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_fines_ibfk_3` FOREIGN KEY (`collected_by_admin_id`) REFERENCES `tbl_admin` (`admin_id`);

--
-- Constraints for table `tbl_learnings`
--
ALTER TABLE `tbl_learnings`
  ADD CONSTRAINT `tbl_learnings_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `tbl_members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_learnings_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `tbl_books` (`book_id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_reservations`
--
ALTER TABLE `tbl_reservations`
  ADD CONSTRAINT `tbl_reservations_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `tbl_members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_reservations_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `tbl_books` (`book_id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_system_alerts`
--
ALTER TABLE `tbl_system_alerts`
  ADD CONSTRAINT `tbl_system_alerts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `tbl_members` (`member_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
