<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Good extends Model
{
    /** @use HasFactory<\Database\Factories\GoodFactory> */
    use HasFactory;

    protected $fillable = [
        'no_sparepart','name', 'unit_price_buy', 'unit_price_sell', 'total_unit'
    ];

    public function detailQuotations()
    {
        return $this->hasMany(DetailQuotation::class, 'id_goods');
    }

    public function detailBuys()
    {
        return $this->hasMany(DetailBuy::class, 'id_goods');
    }
}
