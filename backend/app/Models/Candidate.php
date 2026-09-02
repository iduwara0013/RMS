<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    protected $table = 'candidates';
    protected $primaryKey = 'candidate_id';
    protected $fillable = ['nic', 'name', 'email', 'phone', 'address'];
}
