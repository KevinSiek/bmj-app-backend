<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sparepart extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'part_number',
        'name',
        'unit_price_buy',
        'unit_price_sell',
        'total_unit'
    ];

    public function detailQuotations()
    {
        return $this->hasMany(DetailQuotation::class);
    }

    public function detailBuys()
    {
        return $this->hasMany(DetailBuy::class);
    }

    public function detailBackOrders()
    {
        return $this->hasMany(DetailBackOrder::class);
    }
}
