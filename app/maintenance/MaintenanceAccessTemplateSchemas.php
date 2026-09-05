<?php
declare(strict_types=1);

trait MaintenanceAccessTemplateSchemas
{
    private static function ensureAccessTemplateSchemas(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS position_templates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(80) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(255) NULL,
                access_role ENUM("admin", "staff") NOT NULL DEFAULT "staff",
                default_department_id BIGINT UNSIGNED NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT UNSIGNED NOT NULL DEFAULT 100,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                archived_by BIGINT UNSIGNED NULL,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_position_templates_code (code),
                INDEX idx_position_templates_active (is_active, archived_at, sort_order),
                INDEX idx_position_templates_department (default_department_id),
                CONSTRAINT fk_position_templates_department FOREIGN KEY (default_department_id) REFERENCES departments(id) ON DELETE SET NULL,
                CONSTRAINT fk_position_templates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_position_templates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_position_templates_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS position_template_permissions (
                position_template_id BIGINT UNSIGNED NOT NULL,
                permission_key VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (position_template_id, permission_key),
                INDEX idx_position_template_permissions_key (permission_key),
                CONSTRAINT fk_position_template_permissions_template FOREIGN KEY (position_template_id) REFERENCES position_templates(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Seed organization defaults only for the first template installation.
        // Later schema passes must not recreate departments an owner renamed.
        if ((int) Database::scalar('SELECT COUNT(*) FROM position_templates') === 0) {
            self::seedOrganizationDepartments();
        }
        self::seedPositionTemplates();
    }

    private static function seedOrganizationDepartments(): void
    {
        $departments = [
            'MANAGEMENT' => 'Management',
            'OPERATIONS' => 'Operations',
            'HOUSEKEEPING' => 'Housekeeping & Cleaning',
            'INVENTORY_STORES' => 'Inventory & Stores',
            'FINANCE' => 'Finance',
            'IT' => 'Information Technology',
            'MAINTENANCE' => 'Maintenance',
            'GUEST_SERVICES' => 'Guest Services',
            'BEACH_OPERATIONS' => 'Beach Operations',
        ];

        foreach ($departments as $code => $name) {
            $existing = Database::fetch(
                'SELECT id FROM departments WHERE code = :code OR LOWER(name) = LOWER(:name) LIMIT 1',
                ['code' => $code, 'name' => $name]
            );
            if ($existing !== null) {
                continue;
            }

            Database::execute(
                'INSERT INTO departments (name, code, is_active, deleted_at, created_by, updated_by, created_at, updated_at)
                 VALUES (:name, :code, 1, NULL, NULL, NULL, NOW(), NOW())',
                ['name' => $name, 'code' => $code]
            );
        }
    }

    private static function seedPositionTemplates(): void
    {
        $departmentNames = [
            'MANAGEMENT' => 'Management',
            'OPERATIONS' => 'Operations',
            'HOUSEKEEPING' => 'Housekeeping & Cleaning',
            'INVENTORY_STORES' => 'Inventory & Stores',
            'FINANCE' => 'Finance',
            'IT' => 'Information Technology',
            'MAINTENANCE' => 'Maintenance',
            'GUEST_SERVICES' => 'Guest Services',
            'BEACH_OPERATIONS' => 'Beach Operations',
            'UNASSIGNED' => 'Unassigned',
        ];

        foreach (built_in_position_templates() as $code => $template) {
            $existing = Database::fetch(
                'SELECT id FROM position_templates WHERE code = :code LIMIT 1',
                ['code' => $code]
            );
            if ($existing !== null) {
                continue;
            }

            $departmentCode = (string) $template['department_code'];
            $department = Database::fetch(
                'SELECT id
                 FROM departments
                 WHERE code = :code OR LOWER(name) = LOWER(:name)
                 ORDER BY code = :exact_code DESC
                 LIMIT 1',
                [
                    'code' => $departmentCode,
                    'name' => $departmentNames[$departmentCode] ?? $departmentCode,
                    'exact_code' => $departmentCode,
                ]
            );

            Database::execute(
                'INSERT INTO position_templates (
                    code, name, description, access_role, default_department_id,
                    is_system, is_active, sort_order, created_by, updated_by,
                    archived_by, archived_at, created_at, updated_at
                 ) VALUES (
                    :code, :name, :description, :access_role, :default_department_id,
                    1, 1, :sort_order, NULL, NULL, NULL, NULL, NOW(), NOW()
                 )',
                [
                    'code' => $code,
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'access_role' => $template['access_role'],
                    'default_department_id' => $department['id'] ?? null,
                    'sort_order' => $template['sort_order'],
                ]
            );
            $templateId = Database::lastInsertId();

            foreach ($template['permissions'] as $permission) {
                Database::execute(
                    'INSERT INTO position_template_permissions (position_template_id, permission_key, created_at)
                     VALUES (:position_template_id, :permission_key, NOW())',
                    [
                        'position_template_id' => $templateId,
                        'permission_key' => $permission,
                    ]
                );
            }
        }
    }
}
