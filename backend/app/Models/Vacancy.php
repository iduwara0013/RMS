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
        'opening_date', 'closing_date', 'status',
    ];

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'closing_date' => 'date', 'hod_approved_at' => 'datetime', 'md_approved_at' => 'datetime'];
    }
}
