-- Smart Account Book schema (ASCII comments only)

CREATE DATABASE IF NOT EXISTS account_book
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE account_book;

-- 1. users
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name           VARCHAR(100)    NOT NULL,
    monthly_budget INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. categories
CREATE TABLE IF NOT EXISTS categories (
    id       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id  INT UNSIGNED   NOT NULL,
    name     VARCHAR(100)   NOT NULL,
    type     ENUM('income','expense') NOT NULL,
    icon     VARCHAR(50)    DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_categories_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. transactions
CREATE TABLE IF NOT EXISTS transactions (
    id           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED   NOT NULL,
    category_id  INT UNSIGNED   DEFAULT NULL,
    amount       INT UNSIGNED   NOT NULL,
    description  VARCHAR(255)   DEFAULT NULL,
    source       ENUM('manual','auto','sms','ocr') NOT NULL DEFAULT 'manual',
    tx_date      DATE           NOT NULL,
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transactions_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_tx_user_date ON transactions (user_id, tx_date);
CREATE INDEX idx_tx_category  ON transactions (category_id);
CREATE INDEX idx_tx_source    ON transactions (source);

-- 4. receipts
CREATE TABLE IF NOT EXISTS receipts (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    transaction_id  INT UNSIGNED   DEFAULT NULL,
    image_path      VARCHAR(500)   DEFAULT NULL,
    raw_text        TEXT           DEFAULT NULL,
    parsed_at       DATETIME       DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_receipts_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. budgets
CREATE TABLE IF NOT EXISTS budgets (
    id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED   NOT NULL,
    category_id   INT UNSIGNED   DEFAULT NULL,
    limit_amount  INT UNSIGNED   NOT NULL,
    `year_month`  CHAR(7)        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_budget_user_cat_month (user_id, category_id, `year_month`),
    CONSTRAINT fk_budgets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_budgets_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. recurring_items
CREATE TABLE IF NOT EXISTS recurring_items (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED     NOT NULL,
    category_id   INT UNSIGNED     DEFAULT NULL,
    description   VARCHAR(255)     NOT NULL,
    amount        INT UNSIGNED     NOT NULL,
    day_of_month  TINYINT UNSIGNED NOT NULL,
    is_active     TINYINT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    CONSTRAINT chk_day_of_month CHECK (day_of_month BETWEEN 1 AND 28),
    CONSTRAINT fk_recurring_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_recurring_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seed data
INSERT INTO users (name, monthly_budget) VALUES ('홍길동', 1500000);

INSERT INTO categories (user_id, name, type, icon) VALUES
    (1, '급여',   'income',  'wallet'),
    (1, '식비',   'expense', 'restaurant'),
    (1, '교통비', 'expense', 'bus'),
    (1, '주거비', 'expense', 'home'),
    (1, '여가',   'expense', 'gamepad'),
    (1, '구독',   'expense', 'refresh'),
    (1, '기타',   'expense', 'dots');

INSERT INTO recurring_items (user_id, category_id, description, amount, day_of_month) VALUES
    (1, 4, '월세',           500000, 1),
    (1, 6, '넷플릭스',        17000, 15),
    (1, 6, '유튜브 프리미엄',  14900, 20);
