<?php
declare(strict_types=1);

// Domain module: global search route handlers.

function handle_global_search(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $query = global_search_normalize_query((string) query('q', ''));
    $directTarget = workflow_reference_open_target($query);

    if (mb_strlen($query) < 2) {
        json_response([
            'ok' => true,
            'query' => $query,
            'results' => [],
            'fallback_url' => '',
            'message' => 'Type at least 2 characters.',
        ]);
    }

    json_response([
        'ok' => true,
        'query' => $query,
        'results' => global_search_results($query),
        'fallback_url' => global_search_fallback_url($query),
        'direct_url' => $directTarget['url'] ?? '',
        'direct_reference' => $directTarget['reference'] ?? '',
    ]);
}

function handle_workflow_reference_open(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $reference = workflow_reference_normalize((string) ($params['reference'] ?? ''));

    if ($reference === '') {
        flash('danger', 'Workflow reference is missing.');
        redirect('/dashboard');
    }

    $target = workflow_reference_open_target($reference);

    if ($target !== null) {
        redirect((string) $target['path']);
    }

    flash('danger', 'No workflow matched reference ' . $reference . '.');
    redirect('/dashboard');
}
