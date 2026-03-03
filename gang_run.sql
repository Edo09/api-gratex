-- ================================================
-- SISTEMA DE COLA DE IMPRESIÓN — Gang Run
-- mtldtmte_new_gratexdb
-- ================================================

-- 1. GANG RUNS (primero porque print_jobs la referencia)
-- ================================================
CREATE TABLE `gang_runs` (
  `id`                INT           NOT NULL AUTO_INCREMENT,
  `title`             VARCHAR(255)  NOT NULL,

  `status`            ENUM('assembling','ready','printing','completed') NOT NULL DEFAULT 'assembling',
  `total_jobs`        INT           NOT NULL DEFAULT 0,
  `combined_file_path` VARCHAR(500) NULL,
  `scheduled_at`      DATETIME      NULL,
  `completed_at`      DATETIME      NULL,
  `approved_by`       INT           NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_status`        (`status`),
  INDEX `idx_approved_by`   (`approved_by`),

  CONSTRAINT `fk_gang_runs_approved_by`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. PRINT JOBS
-- ================================================
CREATE TABLE `print_jobs` (
  `id`                INT             NOT NULL AUTO_INCREMENT,
  `client_id`         INT             NOT NULL,

  `gang_run_id`       INT             NULL,

  `file_path`         VARCHAR(500)    NOT NULL,
  `file_format`       VARCHAR(10)     NOT NULL COMMENT 'pdf, ai, png, jpg, tiff',

  `status`            ENUM('pending','queued','processing','completed','cancelled','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason`  TEXT            NULL COMMENT 'Motivo si status=rejected',
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_client_id`     (`client_id`),
  INDEX `idx_gang_run_id`   (`gang_run_id`),
  INDEX `idx_status`        (`status`),
  -- Índice compuesto para el motor de cola (buscar lotes compatibles rápido)
  INDEX `idx_queue_match`   ( `status`),

  CONSTRAINT `fk_print_jobs_client`
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT `fk_print_jobs_gang_run`
    FOREIGN KEY (`gang_run_id`) REFERENCES `gang_runs` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. QUEUE NOTIFICATIONS
-- ================================================
CREATE TABLE `queue_notifications` (
  `id`            INT         NOT NULL AUTO_INCREMENT,
  `print_job_id`  INT         NOT NULL,
  `client_id`     INT         NOT NULL,
  `type`          ENUM('queued','assembled','printing','ready_pickup','cancelled','rejected') NOT NULL,
  `channel`       ENUM('email','sms','push') NOT NULL DEFAULT 'email',
  `message`       TEXT        NOT NULL,
  `is_read`       TINYINT(1)  NOT NULL DEFAULT 0,
  `sent_at`       DATETIME    NULL,
  `created_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_print_job_id` (`print_job_id`),
  INDEX `idx_client_id`    (`client_id`),
  INDEX `idx_is_read`      (`client_id`, `is_read`),

  CONSTRAINT `fk_notifications_job`
    FOREIGN KEY (`print_job_id`) REFERENCES `print_jobs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,

  CONSTRAINT `fk_notifications_client`
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;