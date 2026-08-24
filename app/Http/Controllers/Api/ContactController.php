<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\UpdateContactRequest;
use App\Jobs\ImportContactsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;

class ContactController extends Controller
{
    /**
     * Display a listing of contacts.
     */
    public function index(Request $request)
    {
        $query = Contact::query();

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $contacts = $query->orderBy('name', 'asc')->paginate(15);

        return response()->json($contacts);
    }

    /**
     * Store a newly created contact manually.
     */
    public function store(StoreContactRequest $request)
    {
        $contact = Contact::create([
            'tenant_id' => Auth::user()->tenant_id,
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'added_via' => 'manual',
        ]);

        return response()->json([
            'message' => 'Contact created successfully.',
            'contact' => $contact
        ], 201);
    }

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

    /**
     * Remove the specified contact.
     */
    public function destroy(Request $request, $id)
    {
        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Contact not found.'
            ], 404);
        }

        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully.'
        ]);
    }

    /**
     * Import contacts from a CSV file.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120', // Max 5MB
        ]);

        $file = $request->file('file');
        $filePath = $file->getRealPath();

        $stream = fopen($filePath, 'r');
        $header = fgetcsv($stream);

        if (!$header) {
            fclose($stream);
            return response()->json([
                'error' => 'invalid_csv',
                'message' => 'The uploaded CSV file is empty or invalid.'
            ], 422);
        }

        // Detect column indices based on header keywords
        $nameIdx = 0;
        $phoneIdx = 1;
        
        foreach ($header as $i => $column) {
            $colLower = strtolower(trim($column));
            if (str_contains($colLower, 'name')) {
                $nameIdx = $i;
            } elseif (str_contains($colLower, 'phone') || str_contains($colLower, 'number') || str_contains($colLower, 'mobile')) {
                $phoneIdx = $i;
            }
        }

        $chunk = [];
        $chunkSize = 500;
        $tenantId = Auth::user()->tenant_id;
        $totalQueued = 0;

        while (($row = fgetcsv($stream)) !== false) {
            // Skip rows that don't have the phone column
            if (!isset($row[$phoneIdx]) || empty(trim($row[$phoneIdx]))) {
                continue;
            }

            $chunk[] = [
                'name' => $row[$nameIdx] ?? 'CSV Contact',
                'phone_number' => $row[$phoneIdx],
            ];

            if (count($chunk) >= $chunkSize) {
                ImportContactsJob::dispatch($tenantId, $chunk);
                $totalQueued += count($chunk);
                $chunk = [];
            }
        }

        if (count($chunk) > 0) {
            ImportContactsJob::dispatch($tenantId, $chunk);
            $totalQueued += count($chunk);
        }

        fclose($stream);

        return response()->json([
            'message' => "CSV file accepted. Queued {$totalQueued} contacts for background processing.",
            'total_queued' => $totalQueued
        ]);
    }

    /**
     * Batch sync contacts from mobile device into database.
     */
    public function batchSync(Request $request)
    {
        $request->validate([
            'contacts' => 'required|array|max:2000',
            'contacts.*.name' => 'nullable|string|max:255',
            'contacts.*.phone_number' => 'required|string|max:50',
        ]);

        $tenantId = Auth::user()->tenant_id;
        $contactsData = $request->input('contacts', []);

        $newCount = 0;
        $updatedCount = 0;
        $processedPhones = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId, $contactsData, &$newCount, &$updatedCount, &$processedPhones) {
            foreach ($contactsData as $item) {
                $rawPhone = $item['phone_number'] ?? '';
                // Standardize phone number (digits and optional leading +)
                $phone = preg_replace('/[^\d+]/', '', $rawPhone);

                if (empty($phone)) {
                    continue;
                }

                if (!str_starts_with($phone, '+') && preg_match('/^\d/', $phone)) {
                    $phone = '+' . $phone;
                }

                // Skip numbers that are invalid or already processed in this batch
                if (strlen($phone) < 7 || strlen($phone) > 20 || in_array($phone, $processedPhones, true)) {
                    continue;
                }

                $processedPhones[] = $phone;
                $name = trim($item['name'] ?? '');
                if (empty($name)) {
                    $name = $phone;
                }

                $contact = Contact::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('phone_number', $phone)
                    ->first();

                if ($contact) {
                    // Update name if current name is empty or identical to phone number
                    if (empty($contact->name) || $contact->name === $contact->phone_number) {
                        $contact->update(['name' => $name]);
                        $updatedCount++;
                    }
                } else {
                    Contact::create([
                        'tenant_id' => $tenantId,
                        'name' => $name,
                        'phone_number' => $phone,
                        'added_via' => 'manual',
                    ]);
                    $newCount++;
                }
            }
        });

        $totalSynced = count($processedPhones);

        return response()->json([
            'message' => "Successfully processed {$totalSynced} contacts ({$newCount} added, {$updatedCount} updated).",
            'synced_count' => $totalSynced,
            'new_count' => $newCount,
            'updated_count' => $updatedCount,
        ]);
    }
}
