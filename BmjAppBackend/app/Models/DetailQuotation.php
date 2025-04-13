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
        'quantity',
        'total_unit',
        'unit_price',
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
