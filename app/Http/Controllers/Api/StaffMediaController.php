<?php

namespace App\Http\Controllers\Api;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffDocument;
use App\Domain\Staff\Models\StaffDocumentPage;
use App\Domain\Staff\Services\StaffMediaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StaffMediaController extends Controller
{
    public function storePassport(Request $request, Staff $staff, StaffMediaService $service): JsonResponse
    {
        $this->authorize('update', $staff);
        $validated = $request->validate(['passport' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $service->storePassport($staff, $validated['passport']);

        return response()->json(['message' => 'Staff passport updated successfully.']);
    }

    public function passport(Staff $staff): BinaryFileResponse
    {
        $this->authorize('view', $staff);
        abort_unless($staff->passport_path && Storage::disk('local')->exists($staff->passport_path), 404);

        return response()->file(Storage::disk('local')->path($staff->passport_path), [
            'Content-Type' => $staff->passport_mime_type ?? 'image/jpeg',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function storeDocument(Request $request, Staff $staff, StaffMediaService $service): JsonResponse
    {
        $this->authorize('update', $staff);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'compile_pdf' => ['nullable', 'boolean'],
            'pages' => ['required', 'array', 'min:1', 'max:30'],
            'pages.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        if ((bool) ($validated['compile_pdf'] ?? false) && collect($validated['pages'])->contains(
            fn ($page): bool => ! str_starts_with((string) $page->getMimeType(), 'image/')
        )) {
            throw ValidationException::withMessages([
                'compile_pdf' => 'Single PDF generation supports image pages only.',
            ]);
        }

        $document = $service->storeDocument($staff, $validated, $validated['pages']);

        return response()->json([
            'message' => 'Staff document uploaded successfully.',
            'data' => ['id' => $document->id],
        ]);
    }

    public function page(Staff $staff, StaffDocument $document, StaffDocumentPage $page): BinaryFileResponse
    {
        $this->ensureBelongsToStaff($staff, $document);
        abort_unless((int) $page->staff_document_id === (int) $document->id, 404);
        $this->authorize('view', $staff);
        abort_unless(Storage::disk('local')->exists($page->file_path), 404);

        return response()->file(Storage::disk('local')->path($page->file_path), [
            'Content-Type' => $page->mime_type,
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $page->original_name).'"',
        ]);
    }

    public function compiledPdf(Staff $staff, StaffDocument $document): BinaryFileResponse
    {
        $this->ensureBelongsToStaff($staff, $document);
        $this->authorize('view', $staff);
        abort_unless($document->compiled_pdf_path && Storage::disk('local')->exists($document->compiled_pdf_path), 404);

        return response()->file(Storage::disk('local')->path($document->compiled_pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $document->title).'.pdf"',
        ]);
    }

    public function destroy(Staff $staff, StaffDocument $document, StaffMediaService $service): JsonResponse
    {
        $this->ensureBelongsToStaff($staff, $document);
        $this->authorize('update', $staff);
        $document->load('pages');
        $service->deleteDocument($staff, $document);

        return response()->json(['message' => 'Staff document deleted successfully.']);
    }

    protected function ensureBelongsToStaff(Staff $staff, StaffDocument $document): void
    {
        abort_unless((int) $document->staff_id === (int) $staff->id, 404);
    }
}
