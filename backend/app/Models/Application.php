<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $table = 'applications';
    protected $primaryKey = 'application_id';
    protected $fillable = ['candidate_id', 'vacancy_id', 'submitted_at', 'status'];
    protected function casts(): array { return ['submitted_at' => 'datetime']; }
    public function candidate() { return $this->belongsTo(Candidate::class, 'candidate_id', 'candidate_id'); }
    public function vacancy() { return $this->belongsTo(Vacancy::class, 'vacancy_id', 'vacancy_id'); }
}
