<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'slug', 'company_name', 'office', 'address', 'urban', 'subdistrict', 'city', 'province', 'postal_code'
    ];

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }
}
