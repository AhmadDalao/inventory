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

CREATE TABLE IF NOT EXISTS storages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    storage_type ENUM('warehouse', 'storage') NOT NULL DEFAULT 'storage',
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_storages_status (is_active),
    CONSTRAINT fk_storages_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_storages_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    sku VARCHAR(80) NOT NULL UNIQUE,
    category VARCHAR(120) NULL,
    storage_id BIGINT UNSIGNED NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
    current_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    image_path VARCHAR(255) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_items_status (is_active),
    INDEX idx_items_category (category),
    INDEX idx_items_storage_id (storage_id),
    CONSTRAINT fk_items_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE SET NULL,
    CONSTRAINT fk_items_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_items_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_storage_balances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    storage_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_item_storage (item_id, storage_id),
    INDEX idx_item_storage_quantity (storage_id, quantity),
    CONSTRAINT fk_item_storage_balances_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_storage_balances_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('restock', 'usage', 'adjustment', 'transfer') NOT NULL,
    movement_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    quantity_delta DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    source_storage_id BIGINT UNSIGNED NULL,
    destination_storage_id BIGINT UNSIGNED NULL,
    source_balance_after DECIMAL(12,2) NULL,
    destination_balance_after DECIMAL(12,2) NULL,
    reference_code VARCHAR(100) NULL,
    notes TEXT NULL,
    used_at DATETIME NOT NULL,
    performed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_movements_item_date (item_id, used_at),
    INDEX idx_movements_type (movement_type),
    INDEX idx_movements_source_storage (source_storage_id),
    INDEX idx_movements_destination_storage (destination_storage_id),
    CONSTRAINT fk_movements_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_movements_source_storage FOREIGN KEY (source_storage_id) REFERENCES storages(id) ON DELETE SET NULL,
    CONSTRAINT fk_movements_destination_storage FOREIGN KEY (destination_storage_id) REFERENCES storages(id) ON DELETE SET NULL,
    CONSTRAINT fk_movements_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
