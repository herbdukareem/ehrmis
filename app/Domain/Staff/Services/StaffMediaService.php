<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffDocument;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StaffMediaService
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function storePassport(Staff $staff, UploadedFile $file): Staff
    {
        return DB::transaction(function () use ($staff, $file): Staff {
            $before = $staff->only(['passport_path', 'passport_mime_type']);

            if ($staff->passport_path) {
                Storage::disk('local')->delete($staff->passport_path);
            }

            $path = $file->store("staff/{$staff->id}/passport", 'local');
            $staff->forceFill([
                'passport_path' => $path,
                'passport_mime_type' => $file->getMimeType(),
            ])->save();

            $this->auditLogService->log('staff.passport.updated', $staff, $before, $staff->only(['passport_path', 'passport_mime_type']));

            return $staff->fresh();
        });
    }

    public function storeDocument(Staff $staff, array $data, array $pages): StaffDocument
    {
        return DB::transaction(function () use ($staff, $data, $pages): StaffDocument {
            $document = StaffDocument::query()->create([
                'staff_id' => $staff->id,
                'title' => $data['title'],
                'document_type' => $data['document_type'] ?? null,
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => auth()->id(),
            ]);

            foreach (array_values($pages) as $index => $page) {
                $path = $page->store("staff/{$staff->id}/documents/{$document->id}", 'local');
                $document->pages()->create([
                    'page_number' => $index + 1,
                    'file_path' => $path,
                    'original_name' => $page->getClientOriginalName() ?: 'captured-page-'.($index + 1).'.jpg',
                    'mime_type' => $page->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $page->getSize(),
                ]);
            }

            if ((bool) ($data['compile_pdf'] ?? false)) {
                $this->compileDocumentPdf($staff, $document->load('pages'));
            }

            $this->auditLogService->log('staff.document.uploaded', $staff, [], [
                'document_id' => $document->id,
                'title' => $document->title,
                'pages' => count($pages),
                'compiled_pdf' => (bool) $document->compiled_pdf_path,
            ]);

            return $document->load('pages');
        });
    }

    public function deleteDocument(Staff $staff, StaffDocument $document): void
    {
        DB::transaction(function () use ($staff, $document): void {
            foreach ($document->pages as $page) {
                Storage::disk('local')->delete($page->file_path);
            }

            if ($document->compiled_pdf_path) {
                Storage::disk('local')->delete($document->compiled_pdf_path);
            }

            $before = $document->toArray();
            $document->delete();
            $this->auditLogService->log('staff.document.deleted', $staff, $before);
        });
    }

    protected function compileDocumentPdf(Staff $staff, StaffDocument $document): void
    {
        if ($document->pages->contains(fn ($page): bool => ! str_starts_with($page->mime_type, 'image/'))) {
            throw ValidationException::withMessages([
                'compile_pdf' => 'Single PDF generation supports image pages only.',
            ]);
        }

        $images = $document->pages->map(fn ($page): array => [
            'mime_type' => $page->mime_type,
            'data' => base64_encode(Storage::disk('local')->get($page->file_path)),
        ])->all();
        $html = view('pdf.staff-document', [
            'title' => $document->title,
            'images' => $images,
        ])->render();
        $contents = Pdf::loadHTML($html)->setPaper('a4')->output();
        $path = "staff/{$staff->id}/documents/{$document->id}/compiled.pdf";
        Storage::disk('local')->put($path, $contents);
        $document->forceFill([
            'compiled_pdf_path' => $path,
            'compiled_pdf_size' => strlen($contents),
        ])->save();
    }
}
