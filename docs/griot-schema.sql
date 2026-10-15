CREATE SCHEMA IF NOT EXISTS griot DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE griot;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY categories_slug_unique (slug)
) ENGINE=InnoDB;

CREATE TABLE providers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(120) NOT NULL,
  name VARCHAR(150) NOT NULL,
  bio TEXT NULL,
  city VARCHAR(100) NOT NULL,
  whatsapp VARCHAR(30) NULL,
  instagram VARCHAR(100) NULL,
  avatar_path VARCHAR(255) NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY providers_user_id_unique (user_id),
  UNIQUE KEY providers_slug_unique (slug),
  CONSTRAINT fk_providers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE services (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  price DECIMAL(8,2) NOT NULL,
  duration_min SMALLINT UNSIGNED NOT NULL,
  duration_max SMALLINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_services_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
  CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE service_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  extra_price DECIMAL(8,2) NOT NULL DEFAULT 0,
  extra_duration SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_options_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE availabilities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY availabilities_provider_weekday_index (provider_id, weekday),
  CONSTRAINT fk_availabilities_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  customer_name VARCHAR(150) NOT NULL,
  customer_phone VARCHAR(30) NOT NULL,
  customer_email VARCHAR(255) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY bookings_provider_starts_index (provider_id, starts_at),
  CONSTRAINT fk_bookings_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE booking_service_option (
  booking_id BIGINT UNSIGNED NOT NULL,
  service_option_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (booking_id, service_option_id),
  CONSTRAINT fk_bso_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
  CONSTRAINT fk_bso_option FOREIGN KEY (service_option_id) REFERENCES service_options (id) ON DELETE RESTRICT
) ENGINE=InnoDB;