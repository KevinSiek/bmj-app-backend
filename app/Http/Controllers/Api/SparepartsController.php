<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sparepart;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SparepartsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Sparepart::query();

        // Branch filter
        if ($request->filled('branch') && $request->branch !== 'all') {
            $query->whereHas('branchStock', function ($q) use ($request) {
                $q->where('branch_id', $request->branch);
            });
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('part_number', 'LIKE', "%{$search}%");
            });
        }

        // Pagination
        $perPage = min((int) $request->get('perPage', 50), 100);

        $spareparts = $query
            ->with(['branchStock.branch'])
            ->select(['id','name','part_number','category','selling_price','purchase_price'])
            ->paginate($perPage);

        // Transform for selector
        $spareparts->getCollection()->transform(function ($sparepart) use ($request) {
            $data = [
                'id' => $sparepart->id,
                'name' => $sparepart->name,
                'part_number' => $sparepart->part_number,
                'category' => $sparepart->category,
                'selling_price' => (float) $sparepart->selling_price,
                'default_price' => (float) $sparepart->selling_price,
            ];

            if ($request->filled('branch') && $request->branch !== 'all') {
                $branchStock = $sparepart->branchStock->firstWhere('branch_id', $request->branch);
                $data['stock'] = $branchStock ? (int) $branchStock->quantity : 0;
                $data['min_stock'] = $branchStock ? (int) $branchStock->min_stock : 0;
            } else {
                $data['stock'] = (int) $sparepart->branchStock->sum('quantity');
            }

            return $data;
        });

        return response()->json($spareparts);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $threshold = (int) $request->get('threshold', 10);

        $query = Sparepart::query()
            ->with(['branchStock.branch'])
            ->whereHas('branchStock', function ($q) use ($threshold) {
                $q->whereColumn('quantity', '<=', 'min_stock')
                  ->orWhere('quantity', '<=', $threshold);
            });

        if ($request->filled('branch') && $request->branch !== 'all') {
            $query->whereHas('branchStock', function ($q) use ($request) {
                $q->where('branch_id', $request->branch);
            });
        }

        $items = $query->get()->map(function ($sparepart) {
            return [
                'id' => $sparepart->id,
                'name' => $sparepart->name,
                'part_number' => $sparepart->part_number,
                'branches' => $sparepart->branchStock->map(function ($stock) {
                    return [
                        'branch' => $stock->branch->name,
                        'quantity' => (int) $stock->quantity,
                        'min_stock' => (int) $stock->min_stock,
                    ];
                })
            ];
        });

        return response()->json(['data' => $items, 'total' => $items->count()]);
    }
}
