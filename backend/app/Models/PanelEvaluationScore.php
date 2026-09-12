<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PanelEvaluationScore extends Model
{
    protected $fillable = ['panel_evaluation_id', 'criterion_id', 'score'];
    protected function casts(): array { return ['score' => 'float']; }
}
