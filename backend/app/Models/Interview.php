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
}
