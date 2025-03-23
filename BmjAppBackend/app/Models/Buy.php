<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Buy extends Model
{
    /** @use HasFactory<\Database\Factories\BuyFactory> */
    use HasFactory;

    protected $fillable = [
        'no_buy', 'total_amount', 'review', 'note'
    ];

    public function detailBuys()
    {
        return $this->hasMany(DetailBuy::class, 'id_buy');
    }
}
