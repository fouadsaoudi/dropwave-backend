<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    /**
     * Get all addresses for a specific contact.
     */
    public function index($contactId)
    {
        $contact = Contact::find($contactId);

        if (!$contact) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Contact not found.'
            ], 404);
        }

        $addresses = $contact->addresses()->orderBy('created_at', 'desc')->get();

        return response()->json($addresses);
    }

    /**
     * Add a new address to a contact.
     */
    public function store(Request $request, $contactId)
    {
        $contact = Contact::find($contactId);

        if (!$contact) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Contact not found.'
            ], 404);
        }

        $validated = $request->validate([
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
        ]);

        $address = new Address($validated);
        $address->contact_id = $contact->id;
        $address->tenant_id = $contact->tenant_id;
        $address->save();

        return response()->json([
            'message' => 'Address added successfully.',
            'address' => $address
        ], 201);
    }

    /**
     * Delete a contact's address.
     */
    public function destroy($id)
    {
        $address = Address::find($id);

        if (!$address) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Address not found.'
            ], 404);
        }

        $address->delete();

        return response()->json([
            'message' => 'Address deleted successfully.'
        ]);
    }
}
