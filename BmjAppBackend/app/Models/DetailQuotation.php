<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailQuotation extends Model
{
    /** @use HasFactory<\Database\Factories\DetailQuotationFactory> */
    use HasFactory;

    protected $fillable = [
        'id_quotation', 'id_goods', 'quantity', 'total_unit'
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'id_quotation');
    }

    public function goods()
    {
        return $this->belongsTo(Good::class, 'id_goods');
    }
}
