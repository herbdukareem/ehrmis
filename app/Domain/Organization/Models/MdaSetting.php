<?php

namespace App\Domain\Organization\Models;

use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdaSetting extends Model
{
    protected $fillable = [
        'mda_id', 'acronym', 'domain', 'logo_path', 'vision_html', 'mission_html', 'phone',
        'email', 'head_rank_id', 'head_staff_id', 'head_title', 'signature_path',
        'posting_reference_prefix', 'posting_reference_suffix',
        'link_recurrent_budget_to_annual_allocations',
    ];

    protected function casts(): array
    {
        return [
            'link_recurrent_budget_to_annual_allocations' => 'boolean',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function headRank(): BelongsTo { return $this->belongsTo(Rank::class, 'head_rank_id'); }
    public function headStaff(): BelongsTo { return $this->belongsTo(Staff::class, 'head_staff_id'); }
}
