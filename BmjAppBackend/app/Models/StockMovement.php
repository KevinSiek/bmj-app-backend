<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'sparepart_id',
        'branch_id',
        'delta',
        'source_type',
        'source_id',
        'reason',
        'employee_id',
    ];

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
