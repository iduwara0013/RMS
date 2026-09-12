<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InterviewPanelAssignment extends Model
{
    protected $fillable = ['interview_id', 'user_id', 'assigned_by'];
    public function member() { return $this->belongsTo(User::class, 'user_id'); }
}
