<?php
// config/db.php — DB 연결 + 자동 초기화

define('DB_HOST',    'localhost');
define('DB_NAME',    'account_book');
define('DB_USER',    'root');
define('DB_PASS',    '');          // ← 비밀번호 있으면 입력
define('DB_CHARSET', 'utf8mb4');

function getConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];

    // ① DB 없이 먼저 접속해서 DB·테이블 자동 생성
    $root = new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS, $options
    );
    _initDatabase($root);

    // ② 이후엔 account_book DB로 접속
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS, $options
    );
    return $pdo;
}

function _initDatabase(PDO $pdo): void
{
    // DB 생성
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `account_book`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `account_book`");

    // ── users ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name           VARCHAR(100) NOT NULL DEFAULT '나',
        monthly_budget INT UNSIGNED NOT NULL DEFAULT 0,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 기본 사용자 (없을 때만)
    $pdo->exec("INSERT IGNORE INTO users (id, name) VALUES (1, '나')");

    // ── categories ─────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id         INT UNSIGNED             NOT NULL AUTO_INCREMENT,
        user_id    INT UNSIGNED             NOT NULL DEFAULT 1,
        name       VARCHAR(100)             NOT NULL,
        type       ENUM('income','expense') NOT NULL,
        icon       VARCHAR(50)              DEFAULT NULL,
        created_at DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_cat_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 기본 카테고리 (없을 때만)
    $defaults = [
        [1,'급여',    'income', 'wallet'],
        [1,'용돈',    'income', 'gift'],
        [1,'기타수입','income', 'plus-circle'],
        [1,'식비',    'expense','restaurant'],
        [1,'교통',    'expense','car'],
        [1,'쇼핑',    'expense','cart'],
        [1,'의료',    'expense','hospital'],
        [1,'문화',    'expense','music'],
        [1,'통신',    'expense','phone'],
        [1,'주거',    'expense','home'],
        [1,'기타',    'expense','etc'],
    ];
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE user_id=1")->fetchColumn();
    if ($exists === 0) {
        $st = $pdo->prepare("INSERT INTO categories (user_id,name,type,icon) VALUES (?,?,?,?)");
        foreach ($defaults as $r) $st->execute($r);
    }

    // ── transactions ───────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id             INT UNSIGNED                       NOT NULL AUTO_INCREMENT,
        user_id        INT UNSIGNED                       NOT NULL DEFAULT 1,
        category_id    INT UNSIGNED                       DEFAULT NULL,
        amount         INT UNSIGNED                       NOT NULL,
        description    VARCHAR(255)                       DEFAULT NULL,
        payment_method VARCHAR(50)                        NOT NULL DEFAULT '현금',
        source         ENUM('manual','auto','sms','ocr')  NOT NULL DEFAULT 'manual',
        tx_type        ENUM('expense','income')           DEFAULT NULL,
        tx_date        DATE                               NOT NULL,
        created_at     DATETIME                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_tx_user_date (user_id, tx_date),
        KEY idx_tx_category  (category_id),
        CONSTRAINT fk_tx_user
            FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
        CONSTRAINT fk_tx_category
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── budgets ────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS budgets (
        id              INT UNSIGNED                       NOT NULL AUTO_INCREMENT,
        user_id         INT UNSIGNED                       NOT NULL DEFAULT 1,
        name            VARCHAR(100)                       NOT NULL DEFAULT '전체 예산',
        budget_type     ENUM('weekly','monthly','yearly')  NOT NULL DEFAULT 'monthly',
        category_ids    VARCHAR(500)                       DEFAULT NULL,
        payment_methods VARCHAR(500)                       DEFAULT NULL,
        limit_amount    INT UNSIGNED                       NOT NULL,
        `year_month`    CHAR(7)                            NOT NULL,
        created_at      DATETIME                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_budget_user (user_id),
        CONSTRAINT fk_budget_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── recurring_items ────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_items (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      INT UNSIGNED NOT NULL DEFAULT 1,
        category_id  INT UNSIGNED DEFAULT NULL,
        description  VARCHAR(255) NOT NULL,
        amount       INT UNSIGNED NOT NULL,
        day_of_month TINYINT      NOT NULL,
        is_active    TINYINT(1)   NOT NULL DEFAULT 1,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_rec_user (user_id),
        CONSTRAINT fk_rec_user
            FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
        CONSTRAINT fk_rec_cat
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── receipts ───────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipts (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id INT UNSIGNED DEFAULT NULL,
        image_path     VARCHAR(500) DEFAULT NULL,
        raw_text       TEXT         DEFAULT NULL,
        parsed_at      DATETIME     DEFAULT NULL,
        PRIMARY KEY (id),
        CONSTRAINT fk_receipt_tx
            FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
