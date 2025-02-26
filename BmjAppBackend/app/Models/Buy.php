<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Buy extends Model
{
    /** @use HasFactory<\Database\Factories\BuyFactory> */
    use HasFactory;

    protected $fillable = [
        'id_bo', 'no_buy', 'total_amount', 'review', 'note'
    ];

    public function backOrder()
    {
        return $this->belongsTo(BackOrder::class, 'id_bo');
    }

    public function detailBuys()
    {
        return $this->hasMany(DetailBuy::class, 'id_buy');
    }
}
