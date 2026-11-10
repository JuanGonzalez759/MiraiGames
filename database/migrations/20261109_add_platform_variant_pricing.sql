-- Incremental schema migration for platform-specific pricing, stock and availability.
-- Requires MySQL 8.0.16+ and an existing MiraiGames schema with the
-- 20261020_add_checkout_reservations.sql migration already applied.
-- Take and verify a backup, then complete the documented preflight checks
-- before applying. MySQL DDL implicitly commits; this script is not atomic.

ALTER TABLE games
    ADD COLUMN rawg_id BIGINT UNSIGNED NULL AFTER id,
    ADD UNIQUE INDEX uq_games_rawg_id (rawg_id);

ALTER TABLE game_platforms
    ADD COLUMN price DECIMAL(10, 2) NULL AFTER platform_id,
    ADD COLUMN stock INT UNSIGNED NULL AFTER price,
    ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1 AFTER stock;

UPDATE game_platforms gp
INNER JOIN games g ON g.id = gp.game_id
SET gp.price = g.price,
    gp.stock = 10;

ALTER TABLE game_platforms
    MODIFY COLUMN price DECIMAL(10, 2) NOT NULL,
    MODIFY COLUMN stock INT UNSIGNED NOT NULL DEFAULT 10,
    ADD CONSTRAINT chk_game_platforms_price_nonnegative CHECK (price >= 0),
    ADD CONSTRAINT chk_game_platforms_stock_nonnegative CHECK (stock >= 0),
    ADD CONSTRAINT chk_game_platforms_is_available_boolean CHECK (is_available IN (0, 1));

ALTER TABLE cart_details
    ADD COLUMN platform_id INT UNSIGNED NULL AFTER game_id;

-- Existing cart lines remain NULL until each user explicitly selects a platform.
ALTER TABLE cart_details
    ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (id),
    ADD UNIQUE INDEX uq_cart_details_cart_game_platform
        (cart_id, game_id, platform_id),
    ADD INDEX idx_cart_details_game_platform (game_id, platform_id),
    ADD CONSTRAINT fk_cart_details_game_platform
        FOREIGN KEY (game_id, platform_id)
        REFERENCES game_platforms (game_id, platform_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE;

ALTER TABLE order_details
    ADD COLUMN platform_id INT UNSIGNED NULL AFTER game_id;

-- Existing rows keep NULL: their purchased platform cannot be inferred.
-- New order lines will store the selected platform in the application.
ALTER TABLE order_details
    ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (id),
    ADD UNIQUE INDEX uq_order_details_order_game_platform
        (order_id, game_id, platform_id),
    ADD INDEX idx_order_details_game_platform (game_id, platform_id),
    ADD CONSTRAINT fk_order_details_game_platform
        FOREIGN KEY (game_id, platform_id)
        REFERENCES game_platforms (game_id, platform_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE;
