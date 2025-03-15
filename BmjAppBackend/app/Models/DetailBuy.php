<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailBuy extends Model
{
    /** @use HasFactory<\Database\Factories\DetailBuyFactory> */
    use HasFactory;

    protected $fillable = [
        'id_buy', 'id_spareparts', 'quantity'
    ];

    public function buy()
    {
        return $this->belongsTo(Buy::class, 'id_buy');
    }

    public function spareparts()
    {
        return $this->belongsTo(Sparepart::class, 'id_spareparts');
    }
}
