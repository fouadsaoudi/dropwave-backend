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
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
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

    /**
     * Reverse geocode coordinates using Nominatim (OpenStreetMap) with server-side queueing/throttling.
     */
    public function reverseGeocode(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $lat = $request->input('lat');
        $lng = $request->input('lng');

        // Throttle and lock across all requests to Nominatim (1 req/sec limit)
        $lock = \Illuminate\Support\Facades\Cache::lock('nominatim-geocoder-lock', 10);
        $startTime = microtime(true);

        // Wait up to 10 seconds for the lock to become available
        while (!$lock->get()) {
            usleep(100000); // 100ms
            if (microtime(true) - $startTime > 10.0) {
                return response()->json([
                    'error' => 'timeout',
                    'message' => 'Geocoding service is currently busy. Please try again.'
                ], 429);
            }
        }

        try {
            // Check time since the last successful Nominatim request
            $lastRequestTime = \Illuminate\Support\Facades\Cache::get('nominatim_last_request_time', 0.0);
            $now = microtime(true);
            $timeSinceLast = $now - $lastRequestTime;

            if ($timeSinceLast < 1.0) {
                // Sleep remaining time to guarantee 1 request per second
                usleep((1.0 - $timeSinceLast) * 1000000);
            }

            // Record the current request time before fetching (to block immediate subsequent calls)
            \Illuminate\Support\Facades\Cache::put('nominatim_last_request_time', microtime(true), 60);

            // Call OpenStreetMap Nominatim reverse API
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'Dropwave-App/1.0 (fouad@dropwave.co; contact)'
            ])->get('https://nominatim.openstreetmap.org/reverse', [
                'format' => 'jsonv2',
                'lat' => $lat,
                'lon' => $lng,
                'accept-language' => 'ar,en', // Prefer Arabic or English address details
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Parse address components
                $address = $data['address'] ?? [];
                $road = $address['road'] ?? $address['suburb'] ?? $address['neighbourhood'] ?? null;
                $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? null;
                $state = $address['state'] ?? $address['region'] ?? null;
                $country = $address['country'] ?? null;
                $postalCode = $address['postcode'] ?? null;
                
                $displayName = $data['display_name'] ?? null;

                return response()->json([
                    'road' => $road,
                    'city' => $city,
                    'state' => $state,
                    'country' => $country,
                    'postal_code' => $postalCode,
                    'display_name' => $displayName,
                    'raw' => $data
                ]);
            }

            return response()->json([
                'error' => 'api_failed',
                'message' => 'Failed to resolve location details from geocoding server.'
            ], 502);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Reverse geocoding error: ' . $e->getMessage());
            return response()->json([
                'error' => 'server_error',
                'message' => 'An error occurred during reverse geocoding.'
            ], 500);
        } finally {
            $lock->release();
        }
    }
}
