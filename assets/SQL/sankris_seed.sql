-- Sankris Gymnastics: programas, horarios y cuotas 2026
-- Fuente: Servicios, Horarios y Precios 2026. Precios en quetzales (GTQ).
-- Importante: los nombres de los días están en español, por eso LANG_CODE debe ser ES.
SET NAMES utf8mb4;

INSERT INTO `programs` (`id`, `name`, `age_min`, `age_max`, `max_students`, `duration_minutes`, `area`, `description`, `color`, `sort_order`) VALUES
	(1, 'Baby Gymnastics', 3, 5, 6, 55, 'BabyGym', 'Clases para bebés de 3 a 5 años. Grupos de 6 gimnastas.', '#eb008b', 1),
	(2, 'Start Gymnastics', 6, NULL, 6, 55, 'Gym', 'Clases para niñas y niños desde 6 años. Grupos de 6 participantes.', '#90278e', 2),
	(3, 'Intermediate Gymnastics', 6, 12, 10, 55, 'Gym', 'Clases para niñas de 6 a 12 años. Grupos de 10 gimnastas.', '#6a50a0', 3),
	(4, 'Advanced Gymnastics - Recreativo', 6, 15, 10, 55, 'Gym', 'Clases para niñas de 6 a 15 años. Grupos de 10 gimnastas.', '#3d59a7', 4),
	(5, 'Advanced Gymnastics - Competitivo', 6, 15, 15, 150, 'Gym', 'Clases de 2.5 horas para niñas de 6 a 15 años, niveles 1, 2 y 3+. Hasta 15 gimnastas por grupo. Horarios específicos por nivel: consultar en administración.', '#1f3a7a', 5),
	(6, 'Tae Kwon Do', 6, 15, 15, 55, 'Gym', 'Clases para niñas y niños de 6 a 15 años. Hasta 15 participantes por grupo.', '#e07b00', 6),
	(7, 'Golden Gymnastics', 50, NULL, 8, 60, 'Gym', 'Gimnasia para adultos desde los 50 años, adaptada al nivel de cada participante. Mejora equilibrio, fuerza y movilidad. Clases seguras y supervisadas de 6 a 8 adultos.', '#c9a227', 7);

INSERT INTO `timetable` (`event_name`, `start_time`, `end_time`, `day_of_week`, `color`, `program_id`, `capacity`, `is_evaluation`) VALUES
	('Golden Gymnastics', '09:00:00', '10:00:00', 'Lunes', '#c9a227', 7, 8, 0),
	('Baby 3 años', '15:00:00', '15:55:00', 'Lunes', '#eb008b', 1, 6, 0),
	('Start Gymnastics', '15:30:00', '16:25:00', 'Lunes', '#90278e', 2, 6, 0),
	('Baby 4 y 5 años', '16:00:00', '16:55:00', 'Lunes', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '16:30:00', '17:25:00', 'Lunes', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '16:30:00', '17:25:00', 'Lunes', '#6a50a0', 3, 10, 0),
	('Advanced Recreativo', '16:30:00', '17:25:00', 'Lunes', '#3d59a7', 4, 10, 0),
	('Start Gymnastics', '17:30:00', '18:25:00', 'Lunes', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '17:30:00', '18:25:00', 'Lunes', '#6a50a0', 3, 10, 0),
	('Advanced Recreativo', '17:30:00', '18:25:00', 'Lunes', '#3d59a7', 4, 10, 0),
	('Tae Kwon Do', '18:30:00', '19:25:00', 'Lunes', '#e07b00', 6, 15, 0),
	('Evaluación inicial Tae Kwon Do', '18:30:00', '19:25:00', 'Lunes', '#2e9d6b', 6, 15, 1),
	('Baby 3 años', '15:00:00', '15:55:00', 'Martes', '#eb008b', 1, 6, 0),
	('Baby 4 y 5 años', '15:00:00', '15:55:00', 'Martes', '#f06bb8', 1, 6, 0),
	('Evaluación inicial Baby', '15:00:00', '15:55:00', 'Martes', '#2e9d6b', 1, 6, 1),
	('Start Gymnastics', '15:30:00', '16:25:00', 'Martes', '#90278e', 2, 6, 0),
	('Baby 4 y 5 años', '16:00:00', '16:55:00', 'Martes', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '16:30:00', '17:25:00', 'Martes', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '16:30:00', '17:25:00', 'Martes', '#6a50a0', 3, 10, 0),
	('Baby 4 y 5 años', '17:00:00', '17:55:00', 'Martes', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '17:30:00', '18:25:00', 'Martes', '#90278e', 2, 6, 0),
	('Evaluación inicial Start', '17:30:00', '18:25:00', 'Martes', '#2e9d6b', 2, 6, 1),
	('Intermediate Gymnastics', '17:30:00', '18:25:00', 'Martes', '#6a50a0', 3, 10, 0),
	('Golden Gymnastics', '09:00:00', '10:00:00', 'Miércoles', '#c9a227', 7, 8, 0),
	('Start Gymnastics', '15:30:00', '16:25:00', 'Miércoles', '#90278e', 2, 6, 0),
	('Baby 4 y 5 años', '16:00:00', '16:55:00', 'Miércoles', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '16:30:00', '17:25:00', 'Miércoles', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '16:30:00', '17:25:00', 'Miércoles', '#6a50a0', 3, 10, 0),
	('Advanced Recreativo', '16:30:00', '17:25:00', 'Miércoles', '#3d59a7', 4, 10, 0),
	('Start Gymnastics', '17:30:00', '18:25:00', 'Miércoles', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '17:30:00', '18:25:00', 'Miércoles', '#6a50a0', 3, 10, 0),
	('Advanced Recreativo', '17:30:00', '18:25:00', 'Miércoles', '#3d59a7', 4, 10, 0),
	('Baby 4 y 5 años', '15:00:00', '15:55:00', 'Jueves', '#f06bb8', 1, 6, 0),
	('Evaluación inicial Baby', '15:00:00', '15:55:00', 'Jueves', '#2e9d6b', 1, 6, 1),
	('Start Gymnastics', '15:30:00', '16:25:00', 'Jueves', '#90278e', 2, 6, 0),
	('Baby 4 y 5 años', '16:00:00', '16:55:00', 'Jueves', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '16:30:00', '17:25:00', 'Jueves', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '16:30:00', '17:25:00', 'Jueves', '#6a50a0', 3, 10, 0),
	('Baby 4 y 5 años', '17:00:00', '17:55:00', 'Jueves', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '17:30:00', '18:25:00', 'Jueves', '#90278e', 2, 6, 0),
	('Evaluación inicial Start', '17:30:00', '18:25:00', 'Jueves', '#2e9d6b', 2, 6, 1),
	('Intermediate Gymnastics', '17:30:00', '18:25:00', 'Jueves', '#6a50a0', 3, 10, 0),
	('Baby 4 y 5 años', '15:00:00', '15:55:00', 'Viernes', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '15:30:00', '16:25:00', 'Viernes', '#90278e', 2, 6, 0),
	('Baby 4 y 5 años', '16:00:00', '16:55:00', 'Viernes', '#f06bb8', 1, 6, 0),
	('Start Gymnastics', '16:30:00', '17:25:00', 'Viernes', '#90278e', 2, 6, 0),
	('Start Gymnastics', '17:30:00', '18:25:00', 'Viernes', '#90278e', 2, 6, 0),
	('Baby 4 y 5 años', '08:00:00', '08:55:00', 'Sábado', '#f06bb8', 1, 6, 0),
	('Evaluación inicial Baby', '08:00:00', '08:55:00', 'Sábado', '#2e9d6b', 1, 6, 1),
	('Start Gymnastics', '08:00:00', '08:55:00', 'Sábado', '#90278e', 2, 6, 0),
	('Baby 3 años', '09:00:00', '09:55:00', 'Sábado', '#eb008b', 1, 6, 0),
	('Start Gymnastics', '09:00:00', '09:55:00', 'Sábado', '#90278e', 2, 6, 0),
	('Evaluación inicial Start', '09:00:00', '09:55:00', 'Sábado', '#2e9d6b', 2, 6, 1),
	('Start Gymnastics', '10:00:00', '10:55:00', 'Sábado', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '10:00:00', '10:55:00', 'Sábado', '#6a50a0', 3, 10, 0),
	('Start Gymnastics', '11:00:00', '11:55:00', 'Sábado', '#90278e', 2, 6, 0),
	('Intermediate Gymnastics', '11:00:00', '11:55:00', 'Sábado', '#6a50a0', 3, 10, 0);

INSERT INTO `tickets` (`name`, `expire_days`, `price`, `occasions`, `program_id`) VALUES
	('Inscripción anual Gimnasia', 365, 585.0, NULL, NULL),
	('Inscripción anual Tae Kwon Do', 365, 300.0, NULL, 6),
	('Inscripción anual Golden Gym', 365, 450.0, NULL, 7),
	('Baby Gym - 1 clase x semana', 30, 770.0, 4, 1),
	('Baby Gym - 2 clases x semana', 30, 975.0, 8, 1),
	('Start - 1 clase x semana', 30, 770.0, 4, 2),
	('Start - 2 clases x semana', 30, 975.0, 8, 2),
	('Start - 3 clases x semana', 30, 1200.0, 12, 2),
	('Intermediate - 2 clases x semana', 30, 975.0, 8, 3),
	('Intermediate - 3 clases x semana', 30, 1200.0, 12, 3),
	('Adv. Recreativo - 2 clases x semana', 30, 975.0, 8, 4),
	('Adv. Competitivo - 3 clases x semana', 30, 1500.0, 12, 5),
	('Tae Kwon Do - 1 clase x semana', 30, 450.0, 4, 6),
	('Golden Gym - 1 clase x semana', 30, 450.0, 4, 7),
	('Golden Gym - 2 clases x semana', 30, 575.0, 8, 7),
	('Clase de evaluación inicial', 7, 0.0, 1, NULL);

UPDATE `opening_hours` SET `open_time` = '09:00:00', `close_time` = '19:30:00' WHERE `day` = 1;
UPDATE `opening_hours` SET `open_time` = '15:00:00', `close_time` = '18:30:00' WHERE `day` = 2;
UPDATE `opening_hours` SET `open_time` = '09:00:00', `close_time` = '18:30:00' WHERE `day` = 3;
UPDATE `opening_hours` SET `open_time` = '15:00:00', `close_time` = '18:30:00' WHERE `day` = 4;
UPDATE `opening_hours` SET `open_time` = '15:00:00', `close_time` = '18:30:00' WHERE `day` = 5;
UPDATE `opening_hours` SET `open_time` = '08:00:00', `close_time` = '12:00:00' WHERE `day` = 6;
UPDATE `opening_hours` SET `open_time` = NULL, `close_time` = NULL WHERE `day` = 7;
