-- Sankris módulo: núcleo (fases 1 a 4 del roadmap)
-- Todas las tablas nuevas llevan el prefijo sk_ para no chocar con las de GYM One.

-- Tabla de programas (también la crea el instalador de Sankris)
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

CREATE TABLE IF NOT EXISTS sk_settings (
  `key` varchar(64) NOT NULL,
  `value` text NOT NULL,
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  updated_by bigint(20) DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_staff_roles (
  worker_id bigint(20) NOT NULL,
  role enum('admin','recepcion','coach') NOT NULL,
  trainer_id int(11) DEFAULT NULL,
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_audit_log (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  worker_id bigint(20) DEFAULT NULL,
  actor varchar(40) NOT NULL DEFAULT 'staff',
  entity varchar(40) NOT NULL,
  entity_id bigint(20) DEFAULT NULL,
  action varchar(40) NOT NULL,
  before_json longtext DEFAULT NULL,
  after_json longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY entity (entity, entity_id),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Fase 1: familias, responsables y alumnos =====
CREATE TABLE IF NOT EXISTS sk_families (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(150) NOT NULL,
  status enum('active','inactive') NOT NULL DEFAULT 'active',
  tags varchar(255) DEFAULT NULL,
  notes text DEFAULT NULL,
  external_id varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY external_id (external_id),
  KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_guardians (
  id int(11) NOT NULL AUTO_INCREMENT,
  family_id int(11) NOT NULL,
  first_name varchar(100) NOT NULL,
  last_name varchar(100) NOT NULL,
  relationship varchar(40) DEFAULT NULL,
  email varchar(150) DEFAULT NULL,
  phone varchar(40) DEFAULT NULL,
  is_payer tinyint(1) NOT NULL DEFAULT 0,
  is_emergency_contact tinyint(1) NOT NULL DEFAULT 1,
  tax_id varchar(30) DEFAULT NULL,
  billing_name varchar(150) DEFAULT NULL,
  user_id bigint(20) DEFAULT NULL,
  stripe_customer_id varchar(64) DEFAULT NULL,
  stripe_payment_method_id varchar(64) DEFAULT NULL,
  card_brand varchar(20) DEFAULT NULL,
  card_last4 varchar(4) DEFAULT NULL,
  autopay tinyint(1) NOT NULL DEFAULT 0,
  external_id varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY external_id (external_id),
  UNIQUE KEY stripe_customer_id (stripe_customer_id),
  KEY family_id (family_id),
  KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_students (
  id int(11) NOT NULL AUTO_INCREMENT,
  family_id int(11) NOT NULL,
  first_name varchar(100) NOT NULL,
  last_name varchar(100) NOT NULL,
  birthdate date DEFAULT NULL,
  gender enum('F','M','X') DEFAULT NULL,
  program_id int(11) DEFAULT NULL,
  level varchar(50) DEFAULT NULL,
  status enum('active','inactive','trial') NOT NULL DEFAULT 'active',
  tags varchar(255) DEFAULT NULL,
  notes text DEFAULT NULL,
  medical_notes text DEFAULT NULL,
  emergency_contact_name varchar(150) DEFAULT NULL,
  emergency_contact_phone varchar(40) DEFAULT NULL,
  photo_consent tinyint(1) NOT NULL DEFAULT 0,
  user_id bigint(20) DEFAULT NULL,
  external_id varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY external_id (external_id),
  KEY family_id (family_id),
  KEY program_id (program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Fase 2: planes, suscripciones y créditos =====
CREATE TABLE IF NOT EXISTS sk_plans (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  kind enum('membership','class_pass','fee','camp','makeup') NOT NULL DEFAULT 'membership',
  price decimal(10,2) NOT NULL,
  billing_period enum('monthly','quarterly','semiannual','annual','once') NOT NULL DEFAULT 'monthly',
  credits int(11) DEFAULT NULL,
  credit_period enum('week','month','billing','validity') NOT NULL DEFAULT 'month',
  validity_days int(11) DEFAULT NULL,
  auto_renew tinyint(1) NOT NULL DEFAULT 1,
  is_public tinyint(1) NOT NULL DEFAULT 1,
  active tinyint(1) NOT NULL DEFAULT 1,
  description text DEFAULT NULL,
  external_id varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY external_id (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_plan_programs (
  plan_id int(11) NOT NULL,
  program_id int(11) NOT NULL,
  PRIMARY KEY (plan_id, program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_subscriptions (
  id int(11) NOT NULL AUTO_INCREMENT,
  student_id int(11) NOT NULL,
  family_id int(11) NOT NULL,
  payer_guardian_id int(11) DEFAULT NULL,
  plan_id int(11) NOT NULL,
  -- Copia de las condiciones al contratar: un cambio de tarifa no altera este contrato
  plan_name varchar(120) NOT NULL,
  kind varchar(20) NOT NULL,
  price decimal(10,2) NOT NULL,
  billing_period varchar(20) NOT NULL,
  credits int(11) DEFAULT NULL,
  credit_period varchar(20) NOT NULL,
  validity_days int(11) DEFAULT NULL,
  auto_renew tinyint(1) NOT NULL DEFAULT 1,
  discount_type enum('none','fixed','percent') NOT NULL DEFAULT 'none',
  discount_value decimal(10,2) NOT NULL DEFAULT 0.00,
  discount_cycles int(11) DEFAULT NULL,
  discount_cycles_used int(11) NOT NULL DEFAULT 0,
  status enum('active','paused','cancelled','ended') NOT NULL DEFAULT 'active',
  start_date date NOT NULL,
  end_date date DEFAULT NULL,
  next_billing_date date DEFAULT NULL,
  paused_from date DEFAULT NULL,
  paused_until date DEFAULT NULL,
  cancel_at date DEFAULT NULL,
  notes text DEFAULT NULL,
  external_id varchar(64) DEFAULT NULL,
  created_by bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY external_id (external_id),
  KEY student_id (student_id),
  KEY family_id (family_id),
  KEY next_billing_date (status, next_billing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_subscription_programs (
  subscription_id int(11) NOT NULL,
  program_id int(11) NOT NULL,
  PRIMARY KEY (subscription_id, program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_subscription_events (
  id int(11) NOT NULL AUTO_INCREMENT,
  subscription_id int(11) NOT NULL,
  event enum('created','paused','resumed','cancelled','reactivated','price_changed','renewed','ended') NOT NULL,
  effective_date date NOT NULL,
  details text DEFAULT NULL,
  worker_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY subscription_id (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro de créditos: cada asignación, consumo o devolución es un movimiento
CREATE TABLE IF NOT EXISTS sk_credit_ledger (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  subscription_id int(11) NOT NULL,
  student_id int(11) NOT NULL,
  delta int(11) NOT NULL,
  reason enum('grant','booking','booking_refund','adjustment','expire') NOT NULL,
  period_start date NOT NULL,
  period_end date NOT NULL,
  booking_id bigint(20) DEFAULT NULL,
  note varchar(255) DEFAULT NULL,
  grant_key varchar(80) DEFAULT NULL,
  worker_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY grant_key (grant_key),
  KEY sub_period (subscription_id, period_start, period_end),
  KEY booking_id (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Fase 3: calendario, reservas y asistencia =====

CREATE TABLE IF NOT EXISTS sk_session_exceptions (
  id int(11) NOT NULL AUTO_INCREMENT,
  session_date date NOT NULL,
  timetable_id int(11) DEFAULT NULL,
  reason varchar(150) NOT NULL,
  worker_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY date_slot (session_date, timetable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_recurring_bookings (
  id int(11) NOT NULL AUTO_INCREMENT,
  student_id int(11) NOT NULL,
  timetable_id int(11) NOT NULL,
  start_date date NOT NULL,
  end_date date DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  worker_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY student_id (student_id),
  KEY timetable_id (timetable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_bookings (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  student_id int(11) NOT NULL,
  timetable_id int(11) NOT NULL,
  session_date date NOT NULL,
  subscription_id int(11) DEFAULT NULL,
  source enum('manual','recurring','makeup','evaluation') NOT NULL DEFAULT 'manual',
  status enum('booked','cancelled','late_cancelled','attended','no_show','session_cancelled') NOT NULL DEFAULT 'booked',
  recurring_id int(11) DEFAULT NULL,
  cancelled_at datetime DEFAULT NULL,
  attendance_marked_at datetime DEFAULT NULL,
  attendance_marked_by bigint(20) DEFAULT NULL,
  worker_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY one_per_session (student_id, timetable_id, session_date),
  KEY session (timetable_id, session_date),
  KEY subscription_id (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_booking_alerts (
  id int(11) NOT NULL AUTO_INCREMENT,
  recurring_id int(11) DEFAULT NULL,
  student_id int(11) NOT NULL,
  timetable_id int(11) NOT NULL,
  session_date date NOT NULL,
  reason varchar(60) NOT NULL,
  resolved tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY one_alert (student_id, timetable_id, session_date, reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Fase 4: facturación y cobros =====
CREATE TABLE IF NOT EXISTS sk_sequences (
  name varchar(40) NOT NULL,
  value bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_invoices (
  id int(11) NOT NULL AUTO_INCREMENT,
  number varchar(30) NOT NULL,
  family_id int(11) NOT NULL,
  payer_guardian_id int(11) DEFAULT NULL,
  status enum('open','paid','void','uncollectible') NOT NULL DEFAULT 'open',
  currency varchar(3) NOT NULL DEFAULT 'GTQ',
  issue_date date NOT NULL,
  due_date date NOT NULL,
  subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
  discount_total decimal(10,2) NOT NULL DEFAULT 0.00,
  total decimal(10,2) NOT NULL DEFAULT 0.00,
  amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
  idempotency_key varchar(100) DEFAULT NULL,
  autopay tinyint(1) NOT NULL DEFAULT 0,
  notes text DEFAULT NULL,
  voided_reason varchar(255) DEFAULT NULL,
  external_id varchar(64) DEFAULT NULL,
  created_by bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY number (number),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY external_id (external_id),
  KEY family_status (family_id, status),
  KEY due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_invoice_lines (
  id int(11) NOT NULL AUTO_INCREMENT,
  invoice_id int(11) NOT NULL,
  description varchar(255) NOT NULL,
  student_id int(11) DEFAULT NULL,
  subscription_id int(11) DEFAULT NULL,
  quantity int(11) NOT NULL DEFAULT 1,
  unit_price decimal(10,2) NOT NULL,
  discount decimal(10,2) NOT NULL DEFAULT 0.00,
  amount decimal(10,2) NOT NULL,
  period_start date DEFAULT NULL,
  period_end date DEFAULT NULL,
  PRIMARY KEY (id),
  KEY invoice_id (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_payments (
  id int(11) NOT NULL AUTO_INCREMENT,
  family_id int(11) NOT NULL,
  guardian_id int(11) DEFAULT NULL,
  method enum('cash','transfer','card_external','stripe') NOT NULL,
  amount decimal(10,2) NOT NULL,
  refunded_amount decimal(10,2) NOT NULL DEFAULT 0.00,
  currency varchar(3) NOT NULL DEFAULT 'GTQ',
  received_at datetime NOT NULL,
  reference varchar(120) DEFAULT NULL,
  stripe_payment_intent_id varchar(64) DEFAULT NULL,
  stripe_charge_id varchar(64) DEFAULT NULL,
  idempotency_key varchar(100) DEFAULT NULL,
  notes varchar(255) DEFAULT NULL,
  external_id varchar(64) DEFAULT NULL,
  created_by bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY stripe_payment_intent_id (stripe_payment_intent_id),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY external_id (external_id),
  KEY family_id (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_payment_allocations (
  id int(11) NOT NULL AUTO_INCREMENT,
  payment_id int(11) NOT NULL,
  invoice_id int(11) NOT NULL,
  amount decimal(10,2) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY payment_id (payment_id),
  KEY invoice_id (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_refunds (
  id int(11) NOT NULL AUTO_INCREMENT,
  payment_id int(11) NOT NULL,
  amount decimal(10,2) NOT NULL,
  reason varchar(255) NOT NULL,
  stripe_refund_id varchar(64) DEFAULT NULL,
  idempotency_key varchar(100) NOT NULL,
  worker_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY stripe_refund_id (stripe_refund_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_payment_attempts (
  id int(11) NOT NULL AUTO_INCREMENT,
  invoice_id int(11) NOT NULL,
  attempt_no int(11) NOT NULL,
  amount decimal(10,2) NOT NULL,
  stripe_payment_intent_id varchar(64) DEFAULT NULL,
  status enum('pending','succeeded','failed','requires_action') NOT NULL DEFAULT 'pending',
  error varchar(255) DEFAULT NULL,
  next_retry_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY invoice_attempt (invoice_id, attempt_no),
  KEY retry (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_stripe_events (
  event_id varchar(64) NOT NULL,
  type varchar(80) NOT NULL,
  payload longtext NOT NULL,
  processed_at datetime DEFAULT NULL,
  error varchar(255) DEFAULT NULL,
  received_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_stripe_links (
  id int(11) NOT NULL AUTO_INCREMENT,
  checkout_session_id varchar(100) NOT NULL,
  purpose enum('setup','invoice') NOT NULL,
  guardian_id int(11) NOT NULL,
  invoice_id int(11) DEFAULT NULL,
  url text NOT NULL,
  status enum('open','completed','expired') NOT NULL DEFAULT 'open',
  created_by bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY checkout_session_id (checkout_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sk_settings (`key`, `value`) VALUES
  ('currency', 'GTQ'),
  ('invoice_prefix', 'SK'),
  ('invoice_due_days', '5'),
  ('billing_lead_days', '0'),
  ('retry_schedule_days', '1,3,7'),
  ('recurring_booking_horizon_days', '14'),
  ('public_base_url', '');
