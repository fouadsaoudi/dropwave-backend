<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Driver::query();

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $query->orderBy('name', 'asc')->paginate($perPage);

        // Compute online availability status only for current page items
        $paginated->getCollection()->transform(function ($driver) use ($user) {
            $isOnline = false;
            
            // Find contact matching driver's phone number
            $contact = Contact::where('phone_number', $driver->phone_number)->first();
            if ($contact) {
                // Find conversation
                $conversation = Conversation::where('contact_id', $contact->id)->first();
                if ($conversation && $conversation->window_expires_at) {
                    $isOnline = Carbon::parse($conversation->window_expires_at)->isFuture();
                }
            }

            $driver->is_online = $isOnline;
            return $driver;
        });

        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        // Normalize phone number: ensure it starts with '+' and has no spaces
        $phone = '+' . ltrim(str_replace(' ', '', $request->phone_number), '+');

        // Check uniqueness for this tenant
        $exists = Driver::where('phone_number', $phone)->exists();
        if ($exists) {
            return response()->json([
                'message' => 'A driver with this phone number already exists in your workspace.'
            ], 422);
        }

        $driver = Driver::create([
            'name' => $request->name,
            'phone_number' => $phone,
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'message' => 'Driver created successfully.',
            'driver' => $driver
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $driver = Driver::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:50',
            'is_active' => 'required|boolean',
        ]);

        $phone = '+' . ltrim(str_replace(' ', '', $request->phone_number), '+');

        // Check uniqueness for this tenant (excluding current driver)
        $exists = Driver::where('phone_number', $phone)->where('id', '!=', $driver->id)->exists();
        if ($exists) {
            return response()->json([
                'message' => 'A driver with this phone number already exists in your workspace.'
            ], 422);
        }

        $driver->update([
            'name' => $request->name,
            'phone_number' => $phone,
            'is_active' => $request->is_active,
        ]);

        return response()->json([
            'message' => 'Driver updated successfully.',
            'driver' => $driver
        ]);
    }

    public function destroy($id)
    {
        $driver = Driver::findOrFail($id);
        $driver->delete();

        return response()->json([
            'message' => 'Driver deleted successfully.'
        ]);
    }
}
