-- ============================================================
-- Smart Account Book — MySQL 8.0+ 스키마
-- 사용법: mysql -u root -p account_book < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS account_book
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE account_book;

-- ============================================================
-- 1. users (사용자)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name           VARCHAR(100)    NOT NULL,
    monthly_budget INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '월 예산 (원)',
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='사용자';

-- ============================================================
-- 2. categories (카테고리)
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
    id       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id  INT UNSIGNED   NOT NULL,
    name     VARCHAR(100)   NOT NULL              COMMENT '식비, 교통비, 여가 등',
    type     ENUM('income','expense') NOT NULL    COMMENT '수입/지출 구분',
    icon     VARCHAR(50)    DEFAULT NULL          COMMENT '아이콘 식별자',
    PRIMARY KEY (id),
    CONSTRAINT fk_categories_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='카테고리';

-- ============================================================
-- 3. transactions (거래 내역)
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
    id           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED   NOT NULL,
    category_id  INT UNSIGNED   DEFAULT NULL,
    amount       INT UNSIGNED   NOT NULL              COMMENT '금액 (원)',
    description  VARCHAR(255)   DEFAULT NULL          COMMENT '메모',
    source       ENUM('manual','auto','sms','ocr')
                               NOT NULL DEFAULT 'manual' COMMENT '입력 방법',
    tx_date      DATE           NOT NULL              COMMENT '거래 날짜',
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transactions_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='거래 내역';

CREATE INDEX idx_tx_user_date ON transactions (user_id, tx_date);
CREATE INDEX idx_tx_category  ON transactions (category_id);
CREATE INDEX idx_tx_source    ON transactions (source);

-- ============================================================
-- 4. receipts (영수증)
-- ============================================================
CREATE TABLE IF NOT EXISTS receipts (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    transaction_id  INT UNSIGNED   DEFAULT NULL,
    image_path      VARCHAR(500)   DEFAULT NULL   COMMENT '이미지 파일 경로',
    raw_text        TEXT           DEFAULT NULL   COMMENT 'OCR 원문',
    parsed_at       DATETIME       DEFAULT NULL   COMMENT 'OCR 처리 시각',
    PRIMARY KEY (id),
    CONSTRAINT fk_receipts_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='영수증 (OCR)';

-- ============================================================
-- 5. budgets (월별 예산 한도)
-- ============================================================
CREATE TABLE IF NOT EXISTS budgets (
    id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED   NOT NULL,
    category_id   INT UNSIGNED   DEFAULT NULL   COMMENT 'NULL = 전체 예산',
    limit_amount  INT UNSIGNED   NOT NULL       COMMENT '예산 한도 (원)',
    year_month    CHAR(7)        NOT NULL       COMMENT '예: 2026-03',
    PRIMARY KEY (id),
    UNIQUE KEY uq_budget_user_cat_month (user_id, category_id, year_month),
    CONSTRAINT fk_budgets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_budgets_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='월별 예산 한도';

-- ============================================================
-- 6. recurring_items (반복 자동 입력 항목)
-- ============================================================
CREATE TABLE IF NOT EXISTS recurring_items (
    id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED   NOT NULL,
    category_id   INT UNSIGNED   DEFAULT NULL,
    description   VARCHAR(255)   NOT NULL         COMMENT '항목명 (월세, 넷플릭스 등)',
    amount        INT UNSIGNED   NOT NULL         COMMENT '금액 (원)',
    day_of_month  TINYINT UNSIGNED NOT NULL       COMMENT '매월 실행일 (1~28)',
    is_active     TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '1=활성, 0=비활성',
    PRIMARY KEY (id),
    CONSTRAINT chk_day_of_month CHECK (day_of_month BETWEEN 1 AND 28),
    CONSTRAINT fk_recurring_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_recurring_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='반복 자동 입력 항목 (Cron 사용)';

-- ============================================================
-- 기본 데이터 (테스트용)
-- ============================================================

-- 기본 사용자
INSERT INTO users (name, monthly_budget) VALUES ('홍길동', 1500000);

-- 기본 카테고리 (user_id = 1)
INSERT INTO categories (user_id, name, type, icon) VALUES
    (1, '급여',   'income',  'wallet'),
    (1, '식비',   'expense', 'restaurant'),
    (1, '교통비', 'expense', 'bus'),
    (1, '주거비', 'expense', 'home'),
    (1, '여가',   'expense', 'gamepad'),
    (1, '구독',   'expense', 'refresh'),
    (1, '기타',   'expense', 'dots');

-- 기본 반복 항목 (user_id = 1)
INSERT INTO recurring_items (user_id, category_id, description, amount, day_of_month) VALUES
    (1, 4, '월세',      500000, 1),
    (1, 6, '넷플릭스',   17000, 15),
    (1, 6, '유튜브 프리미엄', 14900, 20);
