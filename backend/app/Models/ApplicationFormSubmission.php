<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationFormSubmission extends Model
{
    protected $fillable = ['application_id', 'form_version_id', 'answers'];
    protected function casts(): array { return ['answers' => 'array']; }
    public function formVersion() { return $this->belongsTo(VacancyFormVersion::class, 'form_version_id'); }
}
