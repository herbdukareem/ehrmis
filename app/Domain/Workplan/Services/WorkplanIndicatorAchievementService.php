<?php
namespace App\Domain\Workplan\Services;
use App\Domain\Workplan\Models\WorkplanIndicator;
class WorkplanIndicatorAchievementService { public function ratio(WorkplanIndicator $indicator, mixed $target, mixed $actual): ?float { if($target===null||$actual===null||(float)$target==0)return null; if($indicator->target_mode?->value==='milestone')return (float)$actual >= (float)$target ? 1.0 : 0.0; if($indicator->direction?->value==='decrease') return (float)$actual<=0 ? null : (float)$target/(float)$actual; return (float)$actual/(float)$target; } }
