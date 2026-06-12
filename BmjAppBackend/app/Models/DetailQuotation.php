<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailQuotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'sparepart_id',
        'service',
        'service_price',
        'quantity',
        'unit_price',
        'unit_price_buy',
        'is_return',
        'stock'
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }

    public function toArray()
    {
        $array = parent::toArray();
        $user = request()->user();

        // If no user is authenticated or the role is NOT Director, hide unit_price_buy
        if (!$user || $user->role !== 'Director') {
            unset($array['unit_price_buy']);
        }

        return $array;
    }
}
