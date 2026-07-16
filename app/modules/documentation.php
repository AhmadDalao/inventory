<?php
declare(strict_types=1);

// Domain module: documentation page handlers and visual helpers. Function names are preserved for route/view compatibility.

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
function documentation_section_count(): int
{
    return count(documentation_sections());
}

function documentation_screenshot_url(string $slug): ?string
{
    $safeSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

    if ($safeSlug === '') {
        return null;
    }

    foreach (['png', 'webp', 'jpg', 'jpeg'] as $extension) {
        $relativePath = 'docs/screenshots/' . $safeSlug . '.' . $extension;

        if (is_file(base_path('assets/' . $relativePath))) {
            return asset_url($relativePath);
        }
    }

    return null;
}

function documentation_visual_for_section(array $section): array
{
    $steps = [];

    foreach (($section['steps'] ?? []) as $step) {
        $step = trim((string) $step);

        if ($step !== '') {
            $steps[] = $step;
        }

        if (count($steps) >= 3) {
            break;
        }
    }

    if ($steps === []) {
        foreach (($section['features'] ?? []) as $feature) {
            $feature = trim((string) $feature);

            if ($feature !== '') {
                $steps[] = $feature;
            }

            if (count($steps) >= 3) {
                break;
            }
        }
    }

    return [
        'screenshot_url' => documentation_screenshot_url((string) ($section['slug'] ?? '')),
        'route' => (string) ($section['route'] ?? ''),
        'title' => (string) ($section['title'] ?? 'System screen'),
        'icon' => (string) ($section['icon'] ?? 'documentation'),
        'steps' => $steps,
    ];
}
