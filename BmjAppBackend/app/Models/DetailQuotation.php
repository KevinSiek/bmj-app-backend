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
}
