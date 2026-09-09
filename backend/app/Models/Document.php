<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';
    protected $primaryKey = 'document_id';
    public $timestamps = false;

    protected $fillable = ['application_id', 'document_type', 'file_name', 'file_path', 'uploaded_at'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id', 'application_id');
    }
}
