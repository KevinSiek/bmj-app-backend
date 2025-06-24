<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailSparepart extends Model
{
    use HasFactory;

    protected $fillable = [
        'sparepart_id',
        'seller_id',
        'unit_price',
        'quantity',
    ];

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }
}
