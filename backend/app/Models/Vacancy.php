<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vacancy extends Model
{
    protected $table = 'vacancies';
    protected $primaryKey = 'vacancy_id';
    public $timestamps = true;

    protected $fillable = [
        'department_id', 'title', 'description', 'vacancy_type',
        'vacancy_grade', 'audience', 'opening_date', 'closing_date', 'status',
        'hr_approved_at', 'hod_approved_at', 'md_approved_at', 'rejection_reason',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'closing_date' => 'date', 'hr_approved_at' => 'datetime', 'hod_approved_at' => 'datetime', 'md_approved_at' => 'datetime'];
    }
}
