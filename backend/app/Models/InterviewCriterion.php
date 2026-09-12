<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InterviewCriterion extends Model
{
    protected $table = 'interview_scorecard_criteria';
    protected $fillable = ['interview_id', 'name', 'weight', 'sort_order'];
    protected function casts(): array { return ['weight' => 'float', 'sort_order' => 'integer']; }
}
