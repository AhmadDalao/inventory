<?php
declare(strict_types=1);

// Domain module: signoff loader and public persistence helpers.
// Function names are preserved for route/view compatibility.

require_once __DIR__ . '/signoff_documents.php';
require_once __DIR__ . '/signoff_data.php';
require_once __DIR__ . '/signoff_assets.php';
require_once __DIR__ . '/signoff_xlsx.php';
require_once __DIR__ . '/signoff_pdf.php';

function ensure_workflow_signoff_pdf(string $workflowType, array $record, array $lines): void
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        return;
    }

    $workflowId = (int) ($record['id'] ?? 0);
    $numberKey = $workflowType === 'handover' ? 'handover_number' : 'request_number';
    $workflowNumber = (string) ($record[$numberKey] ?? '');
    $revisionTimestamp = max(
        workflow_signoff_revision_timestamp($record, $lines),
        workflow_signoff_settings_revision_timestamp()
    );

    if ($workflowId <= 0 || $workflowNumber === '') {
        return;
    }

    $existingPdf = Database::fetch(
        'SELECT id,
                created_at,
                stored_filename,
                mime_type
         FROM workflow_documents
         WHERE workflow_type = :workflow_type
           AND workflow_id = :workflow_id
           AND document_type = "signoff_pdf"
           AND stage = "signoff"
         ORDER BY id DESC
         LIMIT 1',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );

    $existingExcel = Database::fetch(
        'SELECT id,
                created_at,
                stored_filename,
                mime_type
         FROM workflow_documents
         WHERE workflow_type = :workflow_type
           AND workflow_id = :workflow_id
           AND document_type = "signoff_excel"
           AND stage = "signoff"
         ORDER BY id DESC
         LIMIT 1',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );
    $existingExcelIsRealWorkbook = false;

    if ($existingExcel) {
        $storedFilename = (string) ($existingExcel['stored_filename'] ?? '');
        $mimeType = (string) ($existingExcel['mime_type'] ?? '');
        $existingExcelIsRealWorkbook = $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            || strtolower(substr($storedFilename, -5)) === '.xlsx';
        $createdTimestamp = strtotime((string) ($existingExcel['created_at'] ?? '')) ?: 0;
        $existingExcelIsRealWorkbook = $existingExcelIsRealWorkbook
            && str_contains($storedFilename, 'signoff-sheet-img-v14')
            && ($revisionTimestamp === 0 || $createdTimestamp > $revisionTimestamp);
    }
    $existingPdfIsCurrent = false;

    if ($existingPdf) {
        $storedFilename = (string) ($existingPdf['stored_filename'] ?? '');
        $mimeType = (string) ($existingPdf['mime_type'] ?? '');
        $createdTimestamp = strtotime((string) ($existingPdf['created_at'] ?? '')) ?: 0;
        $existingPdfIsCurrent = $mimeType === 'application/pdf'
            && str_contains($storedFilename, 'signoff-img-v14')
            && ($revisionTimestamp === 0 || $createdTimestamp > $revisionTimestamp);
    }

    if ($existingPdfIsCurrent && $existingExcelIsRealWorkbook) {
        return;
    }

    if (!$existingExcelIsRealWorkbook) {
        $storedExcel = store_workflow_excel_document(
            workflow_signoff_excel_payload($workflowType, $record, $lines),
            $workflowType,
            $workflowNumber,
            'signoff'
        );

        create_workflow_document_record(
            $workflowType,
            $workflowId,
            $workflowNumber,
            'signoff_excel',
            'signoff',
            $storedExcel,
            isset($record['created_by']) ? (int) $record['created_by'] : null
        );
    }

    if (!$existingPdfIsCurrent) {
        $stored = store_workflow_pdf_document(
            workflow_signoff_pdf_payload($workflowType, $record, $lines),
            $workflowType,
            $workflowNumber,
            'signoff'
        );

        create_workflow_document_record(
            $workflowType,
            $workflowId,
            $workflowNumber,
            'signoff_pdf',
            'signoff',
            $stored,
            isset($record['created_by']) ? (int) $record['created_by'] : null
        );
    }
}

function save_workflow_proof_upload_if_present(?array $file, string $workflowType, int $workflowId, string $workflowNumber, string $stage, int $uploadedBy): ?int
{
    if ($file === null) {
        return null;
    }

    $stored = store_workflow_proof_document($file, $workflowType, $workflowNumber, $stage);

    return create_workflow_document_record(
        $workflowType,
        $workflowId,
        $workflowNumber,
        'proof_image',
        $stage,
        $stored,
        $uploadedBy
    );
}
