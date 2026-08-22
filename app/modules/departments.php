<?php
declare(strict_types=1);

function department_options(bool $includeDeleted = false): array
{
    return Database::fetchAll(
        'SELECT department.*,
                COUNT(user.id) AS user_count
         FROM departments department
         LEFT JOIN users user ON user.department_id = department.id
         WHERE ' . ($includeDeleted ? '1 = 1' : 'department.deleted_at IS NULL AND department.is_active = 1') . '
         GROUP BY department.id
         ORDER BY CASE WHEN department.code = "UNASSIGNED" THEN 0 ELSE 1 END, department.name ASC'
    );
}

function department_record(?int $departmentId): ?array
{
    if ($departmentId === null || $departmentId <= 0) {
        return null;
    }

    return Database::fetch('SELECT * FROM departments WHERE id = :id LIMIT 1', ['id' => $departmentId]);
}

function unassigned_department_id(): ?int
{
    $id = Database::scalar('SELECT id FROM departments WHERE code = "UNASSIGNED" LIMIT 1');

    return $id === false || $id === null ? null : (int) $id;
}

function valid_department_assignment_id(mixed $value, ?int $fallbackId = null): ?int
{
    $departmentId = normalize_entity_id($value) ?? $fallbackId ?? unassigned_department_id();
    if ($departmentId === null) {
        return null;
    }

    $department = Database::fetch(
        'SELECT id FROM departments WHERE id = :id AND deleted_at IS NULL AND is_active = 1 LIMIT 1',
        ['id' => $departmentId]
    );

    return $department === null ? null : (int) $department['id'];
}

function normalize_department_code(mixed $value, string $name = ''): string
{
    $code = strtoupper(trim((string) $value));
    if ($code === '') {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($name)));
    }

    return substr(trim($code, '_'), 0, 40);
}

function handle_departments_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('departments.view');

    View::render('departments/index', [
        'title' => 'Departments',
        'departments' => department_options(true),
    ]);
}

function handle_department_save_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('departments.manage');
    verify_csrf();

    $id = normalize_entity_id(input('department_id'));
    $name = trim((string) input('name'));
    $code = normalize_department_code(input('code'), $name);
    if ($name === '' || $code === '') {
        flash('danger', 'Department name and code are required.');
        redirect('/departments');
    }

    $duplicate = Database::fetch(
        'SELECT id FROM departments WHERE (LOWER(name) = LOWER(:name) OR code = :code)' . ($id ? ' AND id != :id' : '') . ' LIMIT 1',
        array_filter(['name' => $name, 'code' => $code, 'id' => $id], static fn ($value): bool => $value !== null)
    );
    if ($duplicate !== null) {
        flash('danger', 'That department name or code already exists.');
        redirect('/departments');
    }

    $userId = (int) Auth::id();
    if ($id !== null) {
        Database::execute(
            'UPDATE departments SET name = :name, code = :code, is_active = :is_active, updated_by = :user_id, updated_at = NOW() WHERE id = :id',
            ['name' => $name, 'code' => $code, 'is_active' => input('is_active', '0') === '1' ? 1 : 0, 'user_id' => $userId, 'id' => $id]
        );
        record_activity('department.updated', 'department', $id, 'Department updated: ' . $name);
    } else {
        Database::execute(
            'INSERT INTO departments (name, code, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :code, 1, :user_id, :user_id, NOW(), NOW())',
            ['name' => $name, 'code' => $code, 'user_id' => $userId]
        );
        record_activity('department.created', 'department', Database::lastInsertId(), 'Department created: ' . $name);
    }

    flash('success', 'Department saved.');
    redirect('/departments');
}

function handle_department_archive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('departments.manage');
    verify_csrf();
    $department = department_record((int) ($params['id'] ?? 0));
    if ($department === null || (string) $department['code'] === 'UNASSIGNED') {
        flash('danger', 'That department cannot be archived.');
        redirect('/departments');
    }

    $unassignedId = (int) Database::scalar('SELECT id FROM departments WHERE code = "UNASSIGNED" LIMIT 1');
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        Database::execute('UPDATE users SET department_id = :fallback, updated_at = NOW() WHERE department_id = :id', ['fallback' => $unassignedId, 'id' => $department['id']]);
        Database::execute('UPDATE departments SET is_active = 0, deleted_at = NOW(), deleted_by = :user_id, updated_at = NOW() WHERE id = :id', ['user_id' => Auth::id(), 'id' => $department['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    record_activity('department.archived', 'department', (int) $department['id'], 'Department archived: ' . $department['name']);
    flash('success', 'Department archived. Existing movement history kept its original department snapshot.');
    redirect('/departments');
}

function handle_department_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('departments.manage');
    verify_csrf();
    $department = department_record((int) ($params['id'] ?? 0));
    if ($department === null) {
        flash('danger', 'Department not found.');
        redirect('/departments');
    }
    Database::execute('UPDATE departments SET is_active = 1, deleted_at = NULL, deleted_by = NULL, updated_by = :user_id, updated_at = NOW() WHERE id = :id', ['user_id' => Auth::id(), 'id' => $department['id']]);
    record_activity('department.recovered', 'department', (int) $department['id'], 'Department recovered: ' . $department['name']);
    flash('success', 'Department recovered.');
    redirect('/departments');
}
