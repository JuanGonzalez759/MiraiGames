-- MiraiGames database schema
-- Target: MySQL 8.0.16+ (CHECK constraints are enforced) with InnoDB.
-- Select an empty database before running this script.

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'admin') NOT NULL DEFAULT 'customer',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_users_email UNIQUE (email)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_categories_name UNIQUE (name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE platforms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_platforms_name UNIQUE (name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE games (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    release_date DATE NULL,
    image_url VARCHAR(2048) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_games_price_nonnegative CHECK (price >= 0),
    CONSTRAINT chk_games_stock_nonnegative CHECK (stock >= 0),
    INDEX idx_games_title (title),
    INDEX idx_games_price (price),
    INDEX idx_games_active_price (is_active, price)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE game_categories (
    game_id BIGINT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (game_id, category_id),
    INDEX idx_game_categories_category_game (category_id, game_id),
    CONSTRAINT fk_game_categories_game
        FOREIGN KEY (game_id) REFERENCES games (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_game_categories_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE game_platforms (
    game_id BIGINT UNSIGNED NOT NULL,
    platform_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (game_id, platform_id),
    INDEX idx_game_platforms_platform_game (platform_id, game_id),
    CONSTRAINT fk_game_platforms_game
        FOREIGN KEY (game_id) REFERENCES games (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_game_platforms_platform
        FOREIGN KEY (platform_id) REFERENCES platforms (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE carts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_carts_user_id UNIQUE (user_id),
    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE cart_details (
    cart_id BIGINT UNSIGNED NOT NULL,
    game_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    PRIMARY KEY (cart_id, game_id),
    INDEX idx_cart_details_game (game_id),
    CONSTRAINT chk_cart_details_quantity_positive CHECK (quantity > 0),
    CONSTRAINT fk_cart_details_cart
        FOREIGN KEY (cart_id) REFERENCES carts (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_cart_details_game
        FOREIGN KEY (game_id) REFERENCES games (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    stripe_session_id VARCHAR(255) NULL,
    payment_status ENUM('pending', 'paid', 'failed', 'expired')
        NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    paid_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_orders_stripe_session_id UNIQUE (stripe_session_id),
    CONSTRAINT chk_orders_paid_at_matches_status CHECK (
        (payment_status = 'paid' AND paid_at IS NOT NULL)
        OR
        (payment_status <> 'paid' AND paid_at IS NULL)
    ),
    INDEX idx_orders_user_created (user_id, created_at),
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE order_details (
    order_id BIGINT UNSIGNED NOT NULL,
    game_id BIGINT UNSIGNED NOT NULL,
    game_title VARCHAR(150) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (order_id, game_id),
    INDEX idx_order_details_game (game_id),
    CONSTRAINT chk_order_details_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_order_details_unit_price_nonnegative CHECK (unit_price >= 0),
    CONSTRAINT fk_order_details_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_order_details_game
        FOREIGN KEY (game_id) REFERENCES games (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Initial catalogue data for development.
INSERT INTO categories (id, name) VALUES
    (1, 'Action'),
    (2, 'Adventure'),
    (3, 'RPG'),
    (4, 'Strategy');

INSERT INTO platforms (id, name) VALUES
    (1, 'PC'),
    (2, 'PlayStation 5'),
    (3, 'Nintendo Switch'),
    (4, 'Xbox Series X|S');

INSERT INTO games (
    id, title, description, price, stock, release_date, image_url, is_active
) VALUES
    (
        1,
        'Sakura Circuit',
        'An original arcade racing adventure through a futuristic Japan.',
        19.99,
        25,
        '2025-03-14',
        NULL,
        TRUE
    ),
    (
        2,
        'Moonlit Ronin',
        'An original action role-playing journey across a mythical archipelago.',
        39.99,
        12,
        '2025-06-20',
        NULL,
        TRUE
    ),
    (
        3,
        'Skybound Atelier',
        'An original strategic adventure among floating island settlements.',
        29.50,
        18,
        '2025-09-05',
        NULL,
        TRUE
    );

INSERT INTO game_categories (game_id, category_id) VALUES
    (1, 1),
    (1, 2),
    (2, 1),
    (2, 3),
    (3, 2),
    (3, 4);

INSERT INTO game_platforms (game_id, platform_id) VALUES
    (1, 1),
    (1, 2),
    (2, 1),
    (2, 3),
    (3, 1),
    (3, 4);
