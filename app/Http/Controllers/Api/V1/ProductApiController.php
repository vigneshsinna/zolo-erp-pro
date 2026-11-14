<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductApiController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'search' => 'nullable|string|max:100']);
        $query = Product::catalogFor($this->companyContext($request));

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', "%{$term}%")
                  ->orWhere('code', 'LIKE', "%{$term}%");
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $products = $query->where('is_active', true)->paginate($perPage);

        return $this->sendResponse(\App\Http\Resources\ProductResource::collection($products->getCollection())->resolve($request), 'Products retrieved successfully', 200, [
            'pagination' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ]
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $context = $this->companyContext($request);
        $product = Product::catalogFor($context)->with([
            'productWarehouse' => fn ($q) => $q->visibleIn($context)->with([
                'warehouse' => fn ($w) => $w->forCompany($context)->where('branch_id', $context->branchId),
            ]),
        ])->find($id);

        if (!$product) {
            return $this->sendError('Product not found', [], 404);
        }

        return $this->sendResponse((new \App\Http\Resources\ProductResource($product))->resolve($request), 'Product retrieved successfully');
    }

    public function metadata(Request $request): JsonResponse
    {
        $context = $this->companyContext($request);
        $scope = ['company_id' => $context->companyId];

        $brands = \App\Models\Brand::where('is_active', true)->get(['id', 'title as name']);
        $categories = \App\Models\Category::forCompany($context)->where('is_active', true)->get(['id', 'name', 'parent_id']);
        $units = \App\Models\Unit::where('is_active', true)->get(['id', 'unit_name as name', 'unit_code as code', 'base_unit']);
        $taxes = \App\Models\Tax::where('is_active', true)->get(['id', 'name', 'rate']);
        $taxCategories = \Illuminate\Support\Facades\DB::table('tax_categories')->where($scope)->get(['id', 'name', 'supply_type']);
        $productTypes = \Illuminate\Support\Facades\Schema::hasTable('product_types')
            ? \App\Models\ProductType::forCompany($context->companyId)->where('is_active', true)->get(['id', 'name'])
            : collect();
        $warehouses = \App\Models\Warehouse::forCompany($context)->where('is_active', true)->get(['id', 'name', 'address']);

        return $this->sendResponse([
            'brands' => $brands,
            'categories' => $categories,
            'units' => $units,
            'taxes' => $taxes,
            'tax_categories' => $taxCategories,
            'product_types' => $productTypes,
            'warehouses' => $warehouses,
            'barcode_symbologies' => ['C128', 'C39', 'UPCA', 'UPCE', 'EAN8', 'EAN13'],
            'product_types_allowed' => ['standard', 'combo', 'digital', 'service'],
        ], 'Product metadata retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $context = $this->companyContext($request);
        $service = app(\App\Services\ERP\ProductMasterService::class);

        $data = $request->all();
        if ($request->input('product_usage') === 'purchase') {
            $data['price'] = $request->input('price') ?? 0;
        } elseif ($request->input('product_usage') === 'sale') {
            $data['cost'] = $request->input('cost') ?? 0;
        }

        $product = $service->create($data, $data, $context);

        return $this->sendResponse(
            (new \App\Http\Resources\ProductResource($product->fresh()))->resolve($request),
            'Product created successfully',
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $context = $this->companyContext($request);
        $product = Product::forCompany($context)->whereKey($id)->lockForUpdate()->first();
        if (!$product) {
            return $this->sendError('Product not found', [], 404);
        }

        $service = app(\App\Services\ERP\ProductMasterService::class);
        $data = $request->all();
        if ($request->input('product_usage') === 'purchase') {
            $data['price'] = $request->input('price') ?? 0;
        } elseif ($request->input('product_usage') === 'sale') {
            $data['cost'] = $request->input('cost') ?? 0;
        }

        $updated = $service->update($product, $data, $data, $context);

        return $this->sendResponse(
            (new \App\Http\Resources\ProductResource($updated->fresh()))->resolve($request),
            'Product updated successfully',
            200
        );
    }

    public function search(Request $request, string $term): JsonResponse
    {
        validator(['term' => $term], ['term' => 'required|string|max:100'])->validate();
        $products = Product::catalogFor($this->companyContext($request), ['products.id', 'products.name', 'products.code', 'products.price', 'products.cost', 'products.image'])
            ->where('is_active', true)
            ->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', "%{$term}%")
                  ->orWhere('code', 'LIKE', "%{$term}%");
            })
            ->limit(20)
            ->get();

        return $this->sendResponse(\App\Http\Resources\ProductResource::collection($products)->resolve($request), 'Search results');
    }
}
