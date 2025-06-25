<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'type',
        'current_status',
        'notes',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }
}
