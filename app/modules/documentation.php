<?php
declare(strict_types=1);

// Domain module: documentation. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function handle_documentation_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    View::render('documentation/index', [
        'title' => site_setting('page.documentation', 'Documentation'),
        'sections' => documentation_sections(),
        'importantSections' => documentation_important_sections(),
        'departmentGuides' => documentation_department_guides(),
    ]);
}
