<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    // The existing SQL Server database was created from the ERD and uses
    // dbo.Department (singular), so do not let Eloquent look for departments.
    protected $table = 'Department';

    protected $primaryKey = 'department_id';

    public $timestamps = false;

    protected $fillable = ['department_name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
