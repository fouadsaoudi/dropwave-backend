<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Http\Requests\UpdateContactRequest;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Update customer contact profile.
     */
    public function update(UpdateContactRequest $request, $id)
    {
        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Contact not found.'
            ], 404);
        }

        $contact->update($request->validated());

        return response()->json([
            'message' => 'Contact updated successfully.',
            'contact' => $contact
        ]);
    }
}
