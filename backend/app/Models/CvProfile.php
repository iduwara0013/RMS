<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CvProfile extends Model
{
    protected $table = 'candidate_cv_profiles';
    protected $primaryKey = 'cv_profile_id';

    protected $fillable = [
        'application_id',
        'professional_summary',
        'skills',
        'education',
        'experience',
        'projects',
        'certifications',
        'languages',
        'parse_status',
        'parse_message',
        'confidence_score',
        'review_status',
        'parser_version',
        'parser_metadata',
        'extracted_at',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'education' => 'array',
            'experience' => 'array',
            'projects' => 'array',
            'certifications' => 'array',
            'languages' => 'array',
            'confidence_score' => 'decimal:2',
            'parser_metadata' => 'array',
            'extracted_at' => 'datetime',
        ];
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id', 'application_id');
    }
}
