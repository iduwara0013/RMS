<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $table = 'applications';
    protected $primaryKey = 'application_id';
    protected $fillable = ['candidate_id', 'vacancy_id', 'employee_id', 'applicant_type', 'submitted_at', 'status', 'duplicate_of_application_id'];
    protected function casts(): array { return ['submitted_at' => 'datetime']; }
    public function candidate() { return $this->belongsTo(Candidate::class, 'candidate_id', 'candidate_id'); }
    public function vacancy() { return $this->belongsTo(Vacancy::class, 'vacancy_id', 'vacancy_id'); }
    public function documents() { return $this->hasMany(Document::class, 'application_id', 'application_id'); }
    public function cvProfile() { return $this->hasOne(CvProfile::class, 'application_id', 'application_id'); }
    public function formSubmission() { return $this->hasOne(ApplicationFormSubmission::class, 'application_id', 'application_id'); }
    public function interview() { return $this->hasOne(Interview::class, 'application_id', 'application_id'); }
    public function duplicateOf() { return $this->belongsTo(self::class, 'duplicate_of_application_id', 'application_id'); }
}
