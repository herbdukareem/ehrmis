<?php

namespace App\Services;

use App\Domain\Organization\Models\PlatformSetting;
use App\Domain\Posting\Models\StaffPostingLetter;
use App\Domain\Promotion\Models\PromotionLetter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OfficialLetterPdfService
{
    public function renderPromotionLetter(PromotionLetter $letter): PromotionLetter
    {
        $letter->load([
            'application.cycle',
            'application.mda.setting.headStaff',
            'application.mda.setting.headRank',
            'application.staff.currentEmployment.department',
            'application.staff.currentEmployment.station',
            'application.currentRank',
            'application.currentSalaryScale',
            'application.proposedRank',
            'application.proposedSalaryScale',
            'application.sitting',
            'generator',
        ]);

        $application = $letter->application;
        $setting = $application->mda?->setting;
        $platform = PlatformSetting::query()->first();

        $html = view('pdf.promotion-letter', [
            'letter' => $letter,
            'application' => $application,
            'staff' => $application->staff,
            'mda' => $application->mda,
            'setting' => $setting,
            'platform' => $platform,
            'stateName' => $platform?->state_name ?? 'Niger State',
            'logoData' => $this->embedImage($setting?->logo_path, 'public') ?? $this->embedImage('images/niger-state-logo.jpg', 'public'),
            'signatureData' => $this->embedImage($setting?->signature_path, 'public'),
            'headName' => $setting?->headStaff?->full_name ?? $setting?->headRank?->name ?? 'Authorized Officer',
            'headTitle' => $setting?->head_title ?? 'Head of Establishment',
            'generatedAt' => now(),
        ])->render();

        $path = 'letters/promotions/'.$application->id.'/'.$this->safeFilename($letter->letter_number).'.pdf';
        Storage::disk('local')->put($path, Pdf::loadHTML($html)->setPaper('a4')->output());

        $letter->forceFill(['pdf_path' => $path])->save();

        return $letter->fresh(['application.staff']);
    }

    public function renderPostingLetter(StaffPostingLetter $letter): StaffPostingLetter
    {
        $letter->load([
            'request.staff.currentEmployment.rank',
            'request.fromMda.setting.headStaff',
            'request.fromMda.setting.headRank',
            'request.toMda',
            'request.fromDepartment',
            'request.toDepartment',
            'request.fromStation',
            'request.toStation',
            'generator',
        ]);

        $posting = $letter->request;
        $setting = $posting->fromMda?->setting;
        $platform = PlatformSetting::query()->first();

        $html = view('pdf.posting-letter', [
            'letter' => $letter,
            'posting' => $posting,
            'staff' => $posting->staff,
            'setting' => $setting,
            'platform' => $platform,
            'stateName' => $platform?->state_name ?? 'Niger State',
            'logoData' => $this->embedImage($setting?->logo_path, 'public') ?? $this->embedImage('images/niger-state-logo.jpg', 'public'),
            'signatureData' => $this->embedImage($setting?->signature_path, 'public'),
            'headName' => $setting?->headStaff?->full_name ?? $setting?->headRank?->name ?? 'Authorized Officer',
            'headTitle' => $setting?->head_title ?? 'Head of Establishment',
            'generatedAt' => now(),
        ])->render();

        $path = 'letters/postings/'.$posting->id.'/'.$this->safeFilename($letter->letter_number).'.pdf';
        Storage::disk('local')->put($path, Pdf::loadHTML($html)->setPaper('a4')->output());

        $letter->forceFill(['pdf_path' => $path])->save();

        return $letter->fresh(['request.staff']);
    }

    public function embedImage(?string $path, string $disk): ?string
    {
        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode(Storage::disk($disk)->get($path));
    }

    protected function safeFilename(string $value): string
    {
        return Str::slug($value) ?: 'official-letter';
    }
}
