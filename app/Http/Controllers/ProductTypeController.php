<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use Spatie\Permission\Models\Role;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProductTypeController extends Controller
{
    private function assertPermission(string $permission, ?CompanyContext $context = null): void
    {
        if ($context && Auth::check()) {
            app(CommercialPermission::class)->assert($permission, $context, Auth::id());
            return;
        }
        if (Auth::check()) {
            $role = Role::find(Auth::user()->role_id);
            if ($role && !$role->hasPermissionTo($permission)) {
                abort(403, __('db.not_permitted'));
            }
        }
    }

    /**
     * Display a listing of product types.
     */
    public function index(Request $request)
    {
        $context = app(CompanyContextResolver::class)->forActor();
        $companyId = $context?->companyId ?? session('company_id');

        $types = ProductType::forCompany($companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($types);
        }

        return view('backend.product_type.index', compact('types'));
    }

    /**
     * Store a new product type (supports regular form and modal AJAX).
     */
    public function store(Request $request)
    {
        $context = app(CompanyContextResolver::class)->forActor();
        $this->assertPermission('products-add', $context);
        $companyId = $context?->companyId ?? session('company_id');

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('product_types', 'name')->where(function ($query) use ($companyId) {
                    return $query->where('company_id', $companyId)->orWhereNull('company_id');
                }),
            ],
        ]);

        $productType = ProductType::create([
            'company_id' => $companyId,
            'name' => trim($request->input('name')),
            'parent_id' => $request->input('parent_id'),
            'is_active' => true,
        ]);

        if ($request->ajax() || $request->wantsJson() || $request->input('ajax')) {
            return response()->json([
                'id' => $productType->id,
                'name' => $productType->name,
                'message' => 'Product Type created successfully',
            ]);
        }

        return redirect()->back()->with('message', 'Product Type created successfully');
    }

    /**
     * Return single product type for editing.
     */
    public function edit($id)
    {
        $context = app(CompanyContextResolver::class)->forActor();
        $this->assertPermission('products-edit', $context);
        $companyId = $context?->companyId ?? session('company_id');

        $productType = ProductType::forCompany($companyId)->findOrFail($id);
        return response()->json($productType);
    }

    /**
     * Update product type.
     */
    public function update(Request $request, $id)
    {
        $context = app(CompanyContextResolver::class)->forActor();
        $this->assertPermission('products-edit', $context);
        $companyId = $context?->companyId ?? session('company_id');

        // Only allow updating types owned by the active company
        $productType = ProductType::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('product_types', 'name')->ignore($productType->id)->where(function ($query) use ($companyId) {
                    return $query->where('company_id', $companyId)->orWhereNull('company_id');
                }),
            ],
        ]);

        $productType->update([
            'name' => trim($request->input('name')),
            'parent_id' => $request->input('parent_id'),
            'is_active' => $request->has('is_active') ? (bool) $request->input('is_active') : $productType->is_active,
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'id' => $productType->id,
                'name' => $productType->name,
                'message' => 'Product Type updated successfully',
            ]);
        }

        return redirect()->back()->with('message', 'Product Type updated successfully');
    }

    /**
     * Deactivate / delete product type.
     */
    public function destroy($id)
    {
        $context = app(CompanyContextResolver::class)->forActor();
        $this->assertPermission('products-delete', $context);
        $companyId = $context?->companyId ?? session('company_id');

        // Only allow deleting types owned by the active company
        $productType = ProductType::where('company_id', $companyId)->findOrFail($id);
        $productType->is_active = false;
        $productType->save();

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json(['message' => 'Product Type removed successfully']);
        }

        return redirect()->back()->with('message', 'Product Type removed successfully');
    }
}

