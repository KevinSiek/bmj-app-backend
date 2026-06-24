<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailSparepartMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'sparepart_movement_id',
        'sparepart_id',
        'quantity',
    ];

    public function sparepartMovement()
    {
        return $this->belongsTo(SparepartMovement::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }
}
