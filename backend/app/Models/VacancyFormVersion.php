<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VacancyFormVersion extends Model
{
    protected $fillable = ['vacancy_id', 'version', 'title', 'questions', 'created_by'];
    protected function casts(): array { return ['questions' => 'array', 'version' => 'integer']; }
}
