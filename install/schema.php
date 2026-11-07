<?php
// Database tables for School Manager. Kept as PHP (not .sql) so the FTP deploy, which skips .sql files, uploads it.
return <<<'SQL'
-- School Student & Fee Management System - database schema (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(64) PRIMARY KEY,
  svalue TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS counters (
  name VARCHAR(64) PRIMARY KEY,
  value INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','accountant','clerk','viewer') NOT NULL DEFAULT 'clerk',
  phone VARCHAR(20),
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_years (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(20) NOT NULL UNIQUE,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0,
  next_class_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  name VARCHAR(20) NOT NULL,
  UNIQUE KEY uq_class_section (class_id, name),
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fee_heads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  frequency ENUM('one_time','monthly','quarterly','half_yearly','yearly') NOT NULL DEFAULT 'monthly',
  applies_to ENUM('all','new','old') NOT NULL DEFAULT 'all',
  is_transport TINYINT(1) NOT NULL DEFAULT 0,
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  refundable TINYINT(1) NOT NULL DEFAULT 0,
  late_fee_applies TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fee_structure (
  id INT AUTO_INCREMENT PRIMARY KEY,
  academic_year_id INT NOT NULL,
  class_id INT NOT NULL,
  fee_head_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_fs (academic_year_id, class_id, fee_head_id),
  FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (fee_head_id) REFERENCES fee_heads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transport_routes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  stops TEXT,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS concession_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  kind ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value DECIMAL(10,2) NOT NULL DEFAULT 0,
  fee_head_id INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (fee_head_id) REFERENCES fee_heads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admission_no VARCHAR(30) NOT NULL UNIQUE,
  admission_date DATE NULL,
  first_name VARCHAR(60) NOT NULL,
  last_name VARCHAR(60),
  dob DATE NULL,
  gender ENUM('Male','Female','Other') NULL,
  blood_group VARCHAR(5),
  aadhaar VARCHAR(20),
  category VARCHAR(20),
  religion VARCHAR(30),
  caste_certificate_no VARCHAR(40),
  mother_tongue VARCHAR(30),
  nationality VARCHAR(30) DEFAULT 'Indian',
  photo VARCHAR(120),
  father_name VARCHAR(100),
  father_phone VARCHAR(20),
  father_occupation VARCHAR(60),
  father_email VARCHAR(100),
  mother_name VARCHAR(100),
  mother_phone VARCHAR(20),
  mother_occupation VARCHAR(60),
  guardian_name VARCHAR(100),
  guardian_phone VARCHAR(20),
  guardian_relation VARCHAR(30),
  whatsapp VARCHAR(20),
  annual_income VARCHAR(30),
  address TEXT,
  city VARCHAR(60),
  state VARCHAR(60),
  pincode VARCHAR(10),
  previous_school VARCHAR(150),
  previous_class VARCHAR(30),
  tc_no VARCHAR(40),
  apaar_id VARCHAR(30),
  pen_no VARCHAR(30),
  medical_notes TEXT,
  sibling_of INT NULL,
  is_rte TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','left','passed_out') NOT NULL DEFAULT 'active',
  leaving_date DATE NULL,
  leaving_reason VARCHAR(200),
  tc_issued_no VARCHAR(40),
  remarks TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_name (first_name, last_name),
  KEY idx_phone (father_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  title VARCHAR(100) NOT NULL,
  file_name VARCHAR(150) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  academic_year_id INT NOT NULL,
  class_id INT NOT NULL,
  section_id INT NULL,
  roll_no VARCHAR(10),
  is_new TINYINT(1) NOT NULL DEFAULT 1,
  transport_route_id INT NULL,
  join_date DATE NOT NULL,
  status ENUM('active','left','promoted','detained') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_student_year (student_id, academic_year_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
  FOREIGN KEY (transport_route_id) REFERENCES transport_routes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_concessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  concession_type_id INT NULL,
  kind ENUM('percent','fixed') NOT NULL,
  value DECIMAL(10,2) NOT NULL,
  fee_head_id INT NULL,
  reason VARCHAR(200),
  approved_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
  FOREIGN KEY (concession_type_id) REFERENCES concession_types(id) ON DELETE SET NULL,
  FOREIGN KEY (fee_head_id) REFERENCES fee_heads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fee_demands (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  fee_head_id INT NOT NULL,
  period_key VARCHAR(20) NOT NULL,
  period_label VARCHAR(40) NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  concession DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  waived DECIMAL(10,2) NOT NULL DEFAULT 0,
  late_fee_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  late_fee_waived DECIMAL(10,2) NOT NULL DEFAULT 0,
  carried_forward TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(200),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_demand (enrollment_id, fee_head_id, period_key),
  KEY idx_due (due_date),
  FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
  FOREIGN KEY (fee_head_id) REFERENCES fee_heads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_no VARCHAR(30) NOT NULL UNIQUE,
  student_id INT NOT NULL,
  enrollment_id INT NOT NULL,
  academic_year_id INT NOT NULL,
  receipt_date DATE NOT NULL,
  fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  late_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  late_fee_waived DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  mode VARCHAR(30) NOT NULL,
  reference VARCHAR(100),
  bank_name VARCHAR(100),
  paid_by VARCHAR(100),
  remarks VARCHAR(255),
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled TINYINT(1) NOT NULL DEFAULT 0,
  cancelled_by INT NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(255),
  KEY idx_date (receipt_date),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (enrollment_id) REFERENCES enrollments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT NOT NULL,
  fee_demand_id INT NULL,
  fee_head_id INT NULL,
  description VARCHAR(150) NOT NULL,
  item_type ENUM('fee','late_fee') NOT NULL DEFAULT 'fee',
  amount DECIMAL(10,2) NOT NULL,
  late_fee_waived DECIMAL(10,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
  FOREIGN KEY (fee_demand_id) REFERENCES fee_demands(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  refund_no VARCHAR(30) NOT NULL UNIQUE,
  student_id INT NOT NULL,
  receipt_id INT NULL,
  refund_date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  mode VARCHAR(30) NOT NULL,
  reference VARCHAR(100),
  reason VARCHAR(255) NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cheque_bounces (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT NOT NULL,
  bounce_date DATE NOT NULL,
  charges DECIMAL(10,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255),
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (receipt_id) REFERENCES receipts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50),
  entity_id INT NULL,
  details TEXT,
  ip VARCHAR(45),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SQL;
