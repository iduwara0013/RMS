<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PanelEvaluation extends Model
{
    protected $fillable = ['interview_id', 'evaluator_id', 'weighted_score', 'recommendation', 'comments', 'status', 'submitted_at', 'reopened_at', 'reopened_by'];
    protected function casts(): array { return ['weighted_score' => 'float', 'submitted_at' => 'datetime', 'reopened_at' => 'datetime']; }
    public function member() { return $this->belongsTo(User::class, 'evaluator_id'); }
    public function scores() { return $this->hasMany(PanelEvaluationScore::class); }
}
