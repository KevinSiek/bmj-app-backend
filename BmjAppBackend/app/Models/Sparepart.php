<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sparepart extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'sparepart_number',
        'sparepart_name',
        'unit_price_sell',
        'unit_price_buy',
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

    public function branchStocks(): HasMany
    {
        return $this->hasMany(BranchSparepart::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_spareparts')
            ->using(BranchSparepart::class)
            ->withPivot(['quantity'])
            ->withTimestamps();
    }

    public function getStockForBranch($branch): int
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        if (!$branchId) {
            return 0;
        }

        return (int) ($this->branchStocks()
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0);
    }
}
