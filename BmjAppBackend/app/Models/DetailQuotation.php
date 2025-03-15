<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailQuotation extends Model
{
    /** @use HasFactory<\Database\Factories\DetailQuotationFactory> */
    use HasFactory;

    protected $fillable = [
        'id_quotation', 'id_spareparts', 'quantity', 'total_unit', 'unit_price'
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'id_quotation');
    }

    public function spareparts()
    {
        return $this->belongsTo(Sparepart::class, 'id_spareparts');
    }
}
