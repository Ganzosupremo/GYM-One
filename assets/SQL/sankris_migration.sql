-- Sankris Gymnastics: cambios de base de datos para gimnasia artística.
-- Ejecutar UNA sola vez en una instalación existente de GYM One, y después sankris_seed.sql.
-- Instalaciones nuevas hechas con el instalador de Sankris ya incluyen estos cambios.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `age_min` int(11) DEFAULT NULL,
  `age_max` int(11) DEFAULT NULL,
  `max_students` int(11) DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `area` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '#90278e',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `timetable`
  ADD COLUMN `program_id` int(11) DEFAULT NULL,
  ADD COLUMN `capacity` int(11) DEFAULT NULL,
  ADD COLUMN `is_evaluation` tinyint(1) NOT NULL DEFAULT 0;

ALTER TABLE `tickets`
  ADD COLUMN `program_id` int(11) DEFAULT NULL;

ALTER TABLE `users`
  ADD COLUMN `program_id` int(11) DEFAULT NULL,
  ADD COLUMN `gym_level` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  ADD COLUMN `guardian_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  ADD COLUMN `guardian_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  ADD COLUMN `medical_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL;
