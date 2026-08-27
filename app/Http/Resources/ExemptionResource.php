<?php

namespace App\Http\Resources;

use App\Models\ClubMonthlyDiscount;
use App\Models\MonthlyDiscount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExemptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isClub = $this->resource instanceof ClubMonthlyDiscount || isset($this->club_subscription_id);

        $discountTypeLabels = [
            'full_waiver' => 'إعفاء كلي',
            'humanitarian_fixed' => 'تخفيض إنساني',
            'normal_monthly' => 'تخفيض شهري عادي',
        ];

        $student = null;
        $enrollment = null;
        if ($isClub) {
            $student = $this->subscription?->student ?? $this->resource->subscription?->student;
            $enrollment = $student?->enrollments?->firstWhere('academic_year_id', $this->academic_year_id);
        } else {
            $enrollment = $this->enrollment ?? $this->resource->enrollment;
            $student = $enrollment?->student ?? $this->resource->enrollment?->student;
        }

        $creator = $this->creator;
        $canceller = $this->canceller;

        return [
            'id' => $this->id,
            'type' => $isClub ? 'club' : 'tuition',
            'type_label' => $isClub ? 'معلوم نادي' : 'معلوم دراسي شهري',
            'enrollment_id' => $this->enrollment_id ?? $this->subscription?->enrollment_id,
            'club_subscription_id' => $this->club_subscription_id ?? null,
            'club_name' => $isClub ? ($this->subscription?->club?->name ?? 'النادي') : null,
            'student' => $student ? [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'student_code' => $student->student_code,
                'full_name' => trim($student->first_name . ' ' . $student->last_name),
            ] : null,
            'classroom' => $enrollment?->section ? [
                'section_id' => $enrollment->section_id,
                'section_name' => $enrollment->section->name,
                'level_name' => $enrollment->level?->name,
                'full_name' => trim(($enrollment->level?->name ?? '') . ' ' . $enrollment->section->name),
            ] : null,
            'academic_year_id' => $this->academic_year_id,
            'academic_year_name' => $this->academicYear?->name,
            'discount_type' => $this->discount_type,
            'discount_type_label' => $discountTypeLabels[$this->discount_type] ?? $this->discount_type,
            'monthly_amount' => $this->monthly_amount !== null ? (float) $this->monthly_amount : null,
            'fee_category' => $isClub ? 'club' : ($this->fee_category ?? 'tuition'),
            'start_month' => $this->start_month,
            'end_month' => $this->end_month,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $creator ? trim($creator->first_name . ' ' . $creator->last_name) : null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $canceller ? trim($canceller->first_name . ' ' . $canceller->last_name) : null,
            'cancellation_reason' => $this->cancellation_reason,
            'is_active' => $this->cancelled_at === null,
            'status_color' => $this->cancelled_at !== null
                ? 'slate'
                : ($this->discount_type === 'full_waiver' ? 'emerald' : 'amber'),
        ];
    }
}
