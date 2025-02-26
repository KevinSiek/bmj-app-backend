<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailBuy extends Model
{
    /** @use HasFactory<\Database\Factories\DetailBuyFactory> */
    use HasFactory;

    protected $fillable = [
        'id_buy', 'id_goods', 'quantity'
    ];

    public function buy()
    {
        return $this->belongsTo(Buy::class, 'id_buy');
    }

    public function goods()
    {
        return $this->belongsTo(Good::class, 'id_goods');
    }
}
