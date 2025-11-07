-- ==============================================
-- 🏋️‍♂️ BASE DE DATOS GYMCOACH (versión final)
-- Compatible con MySQL 8+
-- ==============================================

DROP DATABASE IF EXISTS gymcoach;
CREATE DATABASE gymcoach
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

USE gymcoach;

-- ==============================================
-- 👤 Tabla de usuarios
-- ==============================================
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  pin_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==============================================
-- 🧱 Rutinas (nombre, nº de días, usuario)
-- ==============================================
CREATE TABLE routines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  days INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX(user_id)
) ENGINE=InnoDB;

-- ==============================================
-- 📅 Días de rutina
-- ==============================================
CREATE TABLE routine_days (
  id INT AUTO_INCREMENT PRIMARY KEY,
  routine_id INT NOT NULL,
  day_index INT NOT NULL,
  day_name VARCHAR(50) NOT NULL,
  FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_day (routine_id, day_index)
) ENGINE=InnoDB;

-- ==============================================
-- 💪 Ejercicios por día
-- ==============================================
CREATE TABLE day_exercises (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day_id INT NOT NULL,
  exercise_name VARCHAR(100) NOT NULL,
  series INT NOT NULL DEFAULT 3,
  reps_min INT NOT NULL DEFAULT 8,
  reps_max INT NOT NULL DEFAULT 12,
  notes VARCHAR(255) DEFAULT NULL,
  next_target_weight DECIMAL(6,2) DEFAULT NULL,
  next_target_reps INT DEFAULT NULL,
  next_target_series INT DEFAULT NULL,
  FOREIGN KEY (day_id) REFERENCES routine_days(id) ON DELETE CASCADE,
  INDEX(day_id)
) ENGINE=InnoDB;

-- ==============================================
-- ✅ Checks (ejercicios completados)
-- ==============================================
CREATE TABLE exercise_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  day_exercise_id INT NOT NULL,
  check_date DATE NOT NULL,
  checked TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (day_exercise_id) REFERENCES day_exercises(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_check (user_id, day_exercise_id, check_date),
  INDEX(user_id, check_date)
) ENGINE=InnoDB;

-- ==============================================
-- 📈 Logs de entrenamiento (para la sobrecarga)
-- ==============================================
CREATE TABLE exercise_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  routine_id INT NULL,
  day_id INT NULL,
  exercise_name VARCHAR(100) NOT NULL,
  week_number INT NOT NULL,
  weight_kg DECIMAL(6,2) NOT NULL,
  series INT NOT NULL,
  reps_per_set VARCHAR(100) NOT NULL,
  rpe TINYINT NULL,
  observations TEXT NULL,
  session_date DATE NOT NULL DEFAULT (CURRENT_DATE),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX(user_id, exercise_name, session_date),
  INDEX(routine_id),
  INDEX(day_id)
) ENGINE=InnoDB;

-- ==============================================
-- 🔍 Vista de progreso semanal (opcional)
-- ==============================================
CREATE OR REPLACE VIEW v_exercise_progress AS
SELECT
  u.username AS user_name,
  e.exercise_name,
  YEARWEEK(l.session_date, 1) AS year_week,
  AVG(l.weight_kg) AS avg_weight,
  AVG(l.series) AS avg_series,
  AVG(l.rpe) AS avg_rpe,
  COUNT(*) AS sessions
FROM exercise_logs l
JOIN users u ON u.id = l.user_id
JOIN day_exercises e ON e.day_id = l.day_id AND e.exercise_name = l.exercise_name
GROUP BY u.username, e.exercise_name, YEARWEEK(l.session_date, 1)
ORDER BY u.username, e.exercise_name, year_week;

-- ==============================================
-- ✅ FIN DEL SCRIPT
-- ==============================================
