CREATE DATABASE IF NOT EXISTS exam_seating
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE exam_seating;

CREATE TABLE IF NOT EXISTS exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_date DATE NOT NULL,
  exam_name VARCHAR(150) NOT NULL DEFAULT 'Examination',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  subject_code VARCHAR(50) NOT NULL,
  student_count INT NOT NULL,
  UNIQUE KEY uq_exam_subject (exam_id, subject_code),
  CONSTRAINT fk_subject_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS subject_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  student_no INT NOT NULL,
  register_no VARCHAR(80) NOT NULL,
  student_name VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_subject_reg (subject_id, register_no),
  UNIQUE KEY uq_subject_seq (subject_id, student_no),
  CONSTRAINT fk_student_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_name VARCHAR(80) NOT NULL UNIQUE,
  desk_count INT NOT NULL
);

CREATE TABLE IF NOT EXISTS seating (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  room_id INT NOT NULL,
  bench_no INT NOT NULL,
  seat_no TINYINT NOT NULL,
  subject_code VARCHAR(50) NOT NULL,
  student_no INT NOT NULL,
  register_no VARCHAR(80) NOT NULL,
  student_name VARCHAR(150) NOT NULL,
  CONSTRAINT fk_seat_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_seat_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  UNIQUE KEY uq_exam_room_bench_seat (exam_id, room_id, bench_no, seat_no),
  UNIQUE KEY uq_exam_subject_student (exam_id, subject_code, student_no)
);
