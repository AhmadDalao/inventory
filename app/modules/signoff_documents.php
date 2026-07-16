<?php
declare(strict_types=1);

// Domain module: signoff document records. Function names are preserved for compatibility.

function workflow_document_stage_label(string $stage): string
{
    $labels = [
        'signoff' => 'Signature sheet',
        'receipt_report' => 'Receipt proof',
        'closeout_report' => 'Closeout proof',
        'approval' => 'Approval proof',
        'general' => 'General proof',
    ];

    return $labels[$stage] ?? ucwords(str_replace('_', ' ', $stage));
}

function create_workflow_document_record(string $workflowType, int $workflowId, string $workflowNumber, string $documentType, string $stage, array $document, ?int $uploadedBy): int
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        throw new RuntimeException('Invalid workflow document type.');
    }

    if (!in_array($documentType, ['proof_image', 'signoff_pdf', 'signoff_excel'], true)) {
        throw new RuntimeException('Invalid workflow document file type.');
    }

    Database::execute(
        'INSERT INTO workflow_documents (
            workflow_type,
            workflow_id,
            document_type,
            stage,
            original_filename,
            stored_filename,
            mime_type,
            file_size,
            uploaded_by,
            created_at
         ) VALUES (
            :workflow_type,
            :workflow_id,
            :document_type,
            :stage,
            :original_filename,
            :stored_filename,
            :mime_type,
            :file_size,
            :uploaded_by,
            NOW()
         )',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
            'document_type' => $documentType,
            'stage' => $stage !== '' ? $stage : 'general',
            'original_filename' => (string) $document['original_filename'],
            'stored_filename' => (string) $document['stored_filename'],
            'mime_type' => (string) $document['mime_type'],
            'file_size' => (int) $document['file_size'],
            'uploaded_by' => $uploadedBy,
        ]
    );

    $documentId = Database::lastInsertId();
    $document['document_type'] = $documentType;
    register_workflow_document_asset($documentId, $workflowType, $workflowId, $workflowNumber, $document, $uploadedBy);

    return $documentId;
}
