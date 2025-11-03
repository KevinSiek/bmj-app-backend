<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sparepart extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'sparepart_number',
        'sparepart_name',
        'unit_price_sell',
        'unit_price_buy',
        'total_unit',
        'branch'
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

    // New relation to detailSparepart for seller prices
    public function detailSpareparts()
    {
        return $this->hasMany(DetailSparepart::class);
    }
}
