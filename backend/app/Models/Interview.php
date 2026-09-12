<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Interview extends Model
{
    protected $table = 'interviews';
    protected $primaryKey = 'interview_id';
    protected $fillable = ['application_id', 'interview_date', 'interview_time', 'location', 'status'];
    protected function casts(): array { return ['interview_date' => 'date']; }
    public function application() { return $this->belongsTo(Application::class, 'application_id', 'application_id'); }
    public function assignments() { return $this->hasMany(InterviewPanelAssignment::class, 'interview_id', 'interview_id'); }
    public function criteria() { return $this->hasMany(InterviewCriterion::class, 'interview_id', 'interview_id')->orderBy('sort_order'); }
    public function panelEvaluations() { return $this->hasMany(PanelEvaluation::class, 'interview_id', 'interview_id'); }
}
