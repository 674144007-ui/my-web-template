SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

SET NAMES utf8mb4;

CREATE TABLE `assigned_work` (
  `id` int(11) NOT NULL,
  `library_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_level` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_date` date DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `assignment_library` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_level` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `period` int(11) DEFAULT NULL,
  `datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` enum('present','late','absent') COLLATE utf8mb4_unicode_ci DEFAULT 'present'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `qr_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_qr` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `qr_token` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_files` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_schedule` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `time_start` time NOT NULL,
  `time_end` time NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('student','teacher','parent','developer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_level` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_of` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `username`, `password`, `display_name`, `role`, `class_level`, `parent_of`, `created_at`) VALUES
(1, 'PichayaY.', '$2y$10$0FX1Dx8QYDCp1njPfL37je05ovY4tnzMd94bksFyBzKRFTMNxPEfy', 'พิชญะ ยาเครือ (Developer)', 'developer', NULL, NULL, '2025-11-24 15:37:44'),
(2, 'stu001', '$2y$10$gHVWPRhRZ1VsemQB/3FvNe8yzIJBTTAkan2xhjelnNjYOV5M4Jx0.', 'นักเรียน ทดสอบ', 'student', 'ม.1/1', NULL, '2025-11-24 15:37:44'),
(3, 'tea001', '$2y$10$mVcTlryXjwVTvE1whxOJB.b/f8etcel/GMxsuOf8.f.ugGMeTivBS', 'ครู ทดสอบ', 'teacher', NULL, NULL, '2025-11-24 15:37:44'),
(4, 'par001', '$2y$10$MZreO8K2B4GxksAYR.KWW.tGdDFi.3sFCDgmZsUifAllxy1vNWjnO', 'ผู้ปกครอง ทดสอบ', 'parent', NULL, NULL, '2025-11-24 15:37:44');

ALTER TABLE `assigned_work`
  ADD PRIMARY KEY (`id`),
  ADD KEY `library_id` (`library_id`),
  ADD KEY `teacher_id` (`teacher_id`);

ALTER TABLE `assignment_library`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`);

ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

ALTER TABLE `qr_codes`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `student_qr`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `student_id` (`student_id`);

ALTER TABLE `teacher_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`);

ALTER TABLE `teacher_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `parent_of` (`parent_of`);

ALTER TABLE `assigned_work`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `assignment_library`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `qr_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `student_qr`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `teacher_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `teacher_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `assigned_work`
  ADD CONSTRAINT `assigned_work_ibfk_1` FOREIGN KEY (`library_id`) REFERENCES `assignment_library` (`id`),
  ADD CONSTRAINT `assigned_work_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`);

ALTER TABLE `assignment_library`
  ADD CONSTRAINT `assignment_library_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`);

ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

ALTER TABLE `student_qr`
  ADD CONSTRAINT `student_qr_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

ALTER TABLE `teacher_files`
  ADD CONSTRAINT `teacher_files_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`);

ALTER TABLE `teacher_schedule`
  ADD CONSTRAINT `teacher_schedule_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `teacher_schedule_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`parent_of`) REFERENCES `users` (`id`);

COMMIT;
