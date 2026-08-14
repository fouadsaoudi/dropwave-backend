<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactCategory;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = ContactCategory::with('contacts:id')
            ->withCount('contacts')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = ContactCategory::create([
            'tenant_id' => Auth::user()->tenant_id,
            'name' => $request->name,
            'description' => $request->description,
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, $id)
    {
        $category = ContactCategory::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        return response()->json($category);
    }

    public function destroy($id)
    {
        $category = ContactCategory::findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    /**
     * Sync contacts mapped to this category.
     */
    public function syncContacts(Request $request, $id)
    {
        $category = ContactCategory::findOrFail($id);
        
        $request->validate([
            'contact_ids' => 'present|array',
            'contact_ids.*' => 'integer|exists:contacts,id',
        ]);

        // Verify that all contacts belong to the same tenant
        $tenantId = Auth::user()->tenant_id;
        $invalidCount = Contact::whereIn('id', $request->contact_ids)
            ->where('tenant_id', '!=', $tenantId)
            ->count();

        if ($invalidCount > 0) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Some contacts belong to a different tenant.'
            ], 403);
        }

        $category->contacts()->sync($request->contact_ids);

        return response()->json([
            'message' => 'Category contacts synchronized successfully.',
            'contacts_count' => $category->contacts()->count(),
        ]);
    }
}
