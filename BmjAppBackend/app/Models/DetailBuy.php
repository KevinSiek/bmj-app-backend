<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailBuy extends Model
{
    use HasFactory;

    protected $fillable = [
        'buy_id',
        'sparepart_id',
        'quantity',
        'unit_price',
    ];

    public function buy()
    {
        return $this->belongsTo(Buy::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }
}
