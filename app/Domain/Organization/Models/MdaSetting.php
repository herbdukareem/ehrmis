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
    ];

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function headRank(): BelongsTo { return $this->belongsTo(Rank::class, 'head_rank_id'); }
    public function headStaff(): BelongsTo { return $this->belongsTo(Staff::class, 'head_staff_id'); }
}
