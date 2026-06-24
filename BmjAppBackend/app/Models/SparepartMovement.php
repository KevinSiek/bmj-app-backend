<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SparepartMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_number',
        'employee_id',
        'source_branch',
        'target_branch',
        'current_status',
        'status',
        'reason',
    ];

    protected $casts = [
        'status' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function detailSparepartMovements()
    {
        return $this->hasMany(DetailSparepartMovement::class);
    }
}
