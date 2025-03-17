<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sparepart extends Model
{
    /** @use HasFactory<\Database\Factories\SparepartFactory> */
    use HasFactory;

    protected $fillable = [
        'slug','no_sparepart','name', 'unit_price_buy', 'unit_price_sell', 'total_unit'
    ];

    public function detailQuotations()
    {
        return $this->hasMany(DetailQuotation::class, 'id_spareparts');
    }

    public function detailBuys()
    {
        return $this->hasMany(DetailBuy::class, 'id_spareparts');
    }
    public function detailBackOrders()
    {
        return $this->hasMany(DetailBackOrder::class, 'id_spareparts');
    }
}
