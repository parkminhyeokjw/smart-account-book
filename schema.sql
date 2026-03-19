-- ============================================================
-- 똑똑가계부 — 완전 초기화 스키마
-- 실행: phpMyAdmin > SQL 탭에 전체 붙여넣기 > 실행
-- ============================================================

CREATE DATABASE IF NOT EXISTS account_book
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE account_book;

-- ============================================================
-- 1. users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    name           VARCHAR(100)   NOT NULL DEFAULT '기본 사용자',
    monthly_budget INT UNSIGNED   NOT NULL DEFAULT 0,
    created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기본 사용자 (user_id = 1 하드코딩)
INSERT INTO users (name, monthly_budget) VALUES ('나', 0);

-- ============================================================
-- 2. categories
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
    id         INT UNSIGNED                  NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED                  NOT NULL DEFAULT 1,
    name       VARCHAR(100)                  NOT NULL,
    type       ENUM('income','expense')      NOT NULL,
    icon       VARCHAR(50)                   DEFAULT NULL,
    created_at DATETIME                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cat_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기본 카테고리
INSERT INTO categories (user_id, name, type, icon) VALUES
    (1, '급여',     'income',  'wallet'),
    (1, '용돈',     'income',  'gift'),
    (1, '기타수입', 'income',  'plus-circle'),
    (1, '식비',     'expense', 'restaurant'),
    (1, '교통',     'expense', 'car'),
    (1, '쇼핑',     'expense', 'cart'),
    (1, '의료',     'expense', 'hospital'),
    (1, '문화',     'expense', 'music'),
    (1, '통신',     'expense', 'phone'),
    (1, '주거',     'expense', 'home'),
    (1, '기타',     'expense', 'etc');

-- ============================================================
-- 3. transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
    id             INT UNSIGNED                            NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED                            NOT NULL DEFAULT 1,
    category_id    INT UNSIGNED                            DEFAULT NULL,
    amount         INT UNSIGNED                            NOT NULL,
    description    VARCHAR(255)                            DEFAULT NULL,
    payment_method VARCHAR(50)                             NOT NULL DEFAULT '현금',
    source         ENUM('manual','auto','sms','ocr')       NOT NULL DEFAULT 'manual',
    tx_type        ENUM('expense','income')                DEFAULT NULL
                   COMMENT '카테고리 없을 때 지출/수입 구분',
    tx_date        DATE                                    NOT NULL,
    created_at     DATETIME                                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tx_user_date  (user_id, tx_date),
    KEY idx_tx_category   (category_id),
    CONSTRAINT fk_tx_user
        FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
    CONSTRAINT fk_tx_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. budgets
-- ============================================================
CREATE TABLE IF NOT EXISTS budgets (
    id              INT UNSIGNED                          NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED                          NOT NULL DEFAULT 1,
    name            VARCHAR(100)                          NOT NULL DEFAULT '전체 예산',
    budget_type     ENUM('weekly','monthly','yearly')     NOT NULL DEFAULT 'monthly',
    category_ids    VARCHAR(500)                          DEFAULT NULL
                    COMMENT '쉼표 구분 category_id 목록. NULL = 전체',
    payment_methods VARCHAR(500)                          DEFAULT NULL
                    COMMENT '쉼표 구분 결제수단 목록. NULL = 전체',
    limit_amount    INT UNSIGNED                          NOT NULL,
    year_month      CHAR(7)                               NOT NULL COMMENT '예: 2026-03',
    created_at      DATETIME                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_budget_user (user_id),
    CONSTRAINT fk_budget_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. recurring_items
-- ============================================================
CREATE TABLE IF NOT EXISTS recurring_items (
    id           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED   NOT NULL DEFAULT 1,
    category_id  INT UNSIGNED   DEFAULT NULL,
    description  VARCHAR(255)   NOT NULL,
    amount       INT UNSIGNED   NOT NULL,
    day_of_month TINYINT        NOT NULL COMMENT '매월 몇 일 (1~28)',
    is_active    TINYINT(1)     NOT NULL DEFAULT 1,
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recurring_user (user_id),
    CONSTRAINT fk_recurring_user
        FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
    CONSTRAINT fk_recurring_cat
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. receipts (OCR 영수증)
-- ============================================================
CREATE TABLE IF NOT EXISTS receipts (
    id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    transaction_id INT UNSIGNED   DEFAULT NULL,
    image_path     VARCHAR(500)   DEFAULT NULL,
    raw_text       TEXT           DEFAULT NULL,
    parsed_at      DATETIME       DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_receipt_tx
        FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
