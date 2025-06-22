<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WoUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_wo',
        'job_descriptions',
        'unit_type',
        'quantity',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'id_wo');
    }
}
