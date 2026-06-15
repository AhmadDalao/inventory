CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'admin') NOT NULL DEFAULT 'admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    sku VARCHAR(80) NOT NULL UNIQUE,
    category VARCHAR(120) NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
    current_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_items_status (is_active),
    INDEX idx_items_category (category),
    CONSTRAINT fk_items_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_items_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('restock', 'usage', 'adjustment') NOT NULL,
    quantity_delta DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_code VARCHAR(100) NULL,
    notes TEXT NULL,
    used_at DATETIME NOT NULL,
    performed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_movements_item_date (item_id, used_at),
    INDEX idx_movements_type (movement_type),
    CONSTRAINT fk_movements_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_movements_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
