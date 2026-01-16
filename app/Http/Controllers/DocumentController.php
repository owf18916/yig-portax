<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\TaxCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Upload a document
     * 
     * POST /api/documents
     * 
     * Request body:
     * - file (required): PDF file
     * - tax_case_id (required): ID of the tax case
     * - documentable_type (required): Model class name (e.g., "App\Models\SptFiling")
     * - documentable_id (required): ID of the documentable model
     * - stage_code (required): Stage code (e.g., "1", "4", "7")
     * - document_type (required): Type of document (e.g., "supporting_document")
     * - description (optional): Document description
     */
    public function store(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf|max:10240', // Max 10MB
            'tax_case_id' => 'required|integer|exists:tax_cases,id',
            'documentable_type' => 'required|string',
            'documentable_id' => 'required|integer',
            'stage_code' => 'required|string',
            'document_type' => 'required|string',
            'description' => 'nullable|string|max:1000',
        ]);

        $file = $request->file('file');
        $taxCaseId = $validated['tax_case_id'];

        try {
            // Generate unique filename
            $filename = $this->generateFilename($file);
            
            // Get storage disk from config (local or nas)
            $disk = config('filesystems.default');
            
            // Create directory structure: tax_cases/{caseId}/documents/{stageCode}/
            $path = "tax_cases/{$taxCaseId}/documents/{$validated['stage_code']}";
            
            // Store file
            $filePath = Storage::disk($disk)->putFileAs(
                $path,
                $file,
                $filename
            );

            // Calculate file hash for duplicate detection
            $fileHash = hash_file('sha256', $file->getRealPath());

            // Create document record
            $document = Document::create([
                'documentable_type' => $validated['documentable_type'],
                'documentable_id' => $validated['documentable_id'],
                'tax_case_id' => $taxCaseId,
                'document_type' => $validated['document_type'],
                'stage_code' => $validated['stage_code'],
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'hash' => $fileHash,
                'description' => $validated['description'] ?? null,
                'uploaded_by' => auth()->id(),
                'uploaded_at' => now(),
                'status' => 'DRAFT',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => [
                    'id' => $document->id,
                    'original_filename' => $document->original_filename,
                    'file_size' => $document->file_size,
                    'status' => $document->status,
                    'uploaded_at' => $document->uploaded_at->toIso8601String(),
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload document: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View a document inline in browser (for iframe)
     * 
     * GET /api/documents/{id}/view
     */
    public function view(Document $document)
    {
        try {
            // Check if document exists and file is available
            $disk = config('filesystems.default');
            
            if (!Storage::disk($disk)->exists($document->file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document file not found',
                ], 404);
            }

            // Return file with inline disposition (for viewing in iframe)
            return response()->file(
                Storage::disk($disk)->path($document->file_path),
                [
                    'Content-Type' => $document->file_mime_type,
                    'Content-Disposition' => 'inline; filename="' . $document->original_filename . '"',
                ]
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to view document: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download/serve a document
     * 
     * GET /api/documents/{id}/download
     */
    public function download(Document $document)
    {
        try {
            // Check if document exists and file is available
            $disk = config('filesystems.default');
            
            if (!Storage::disk($disk)->exists($document->file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document file not found',
                ], 404);
            }

            // Return file download response
        return response()->download(
            Storage::disk($disk)->path($document->file_path),
            $document->original_filename,
            [
                'Content-Type' => $document->file_mime_type,
            ]
        );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download document: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List documents for a tax case
     * 
     * GET /api/documents?tax_case_id={id}&stage_code={code}
     */
    public function index(Request $request)
    {
        $query = Document::withoutTrashed(); // Only show active (non-deleted) documents

        // Filter by tax_case_id if provided
        if ($request->has('tax_case_id')) {
            $query->where('tax_case_id', $request->get('tax_case_id'));
        }

        // Filter by stage_code if provided
        if ($request->has('stage_code')) {
            $query->where('stage_code', $request->get('stage_code'));
        }

        // Filter by status (supports comma-separated values like "DRAFT,ACTIVE,ARCHIVED")
        if ($request->has('status')) {
            $statusValues = array_map('trim', explode(',', $request->get('status')));
            $query->whereIn('status', $statusValues);
        } else {
            // Default: only show ACTIVE documents
            $query->where('status', 'ACTIVE');
        }

        // Order by upload date (newest first)
        $documents = $query->orderByDesc('uploaded_at')->get();

        return response()->json([
            'success' => true,
            'data' => $documents->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'original_filename' => $doc->original_filename,
                    'file_size' => $doc->file_size,
                    'document_type' => $doc->document_type,
                    'stage_code' => $doc->stage_code,
                    'status' => $doc->status,
                    'uploaded_at' => $doc->uploaded_at->toIso8601String(),
                    'uploaded_by' => $doc->uploadedBy?->name,
                ];
            }),
        ]);
    }

    /**
     * Delete a document (soft delete)
     * 
     * DELETE /api/documents/{id}
     */
    public function destroy(Document $document)
    {
        try {
            // Soft delete the document
            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a unique filename with timestamp and random string
     */
    private function generateFilename($file): string
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);
        
        return "{$timestamp}_{$random}.{$extension}";
    }
}
