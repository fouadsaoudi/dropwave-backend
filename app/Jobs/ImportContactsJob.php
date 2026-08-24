<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\PhoneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ImportContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $tenantId;
    protected array $contacts;

    /**
     * Create a new job instance.
     */
    public function __construct(int $tenantId, array $contacts)
    {
        $this->tenantId = $tenantId;
        $this->contacts = $contacts;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $batch = [];
        $now = Carbon::now();

        foreach ($this->contacts as $contact) {
            // Normalize phone number to E.164
            $phone = PhoneService::normalize($contact['phone_number'] ?? '');
            
            if (empty($phone)) {
                continue;
            }

            // Ensure normalized number is formatted as a valid E.164 string
            if (!preg_match('/^\+?[1-9]\d{1,14}$/', $phone)) {
                continue; // Skip invalid numbers
            }

            $batch[] = [
                'tenant_id' => $this->tenantId,
                'name' => substr(trim($contact['name'] ?? 'CSV Contact'), 0, 255),
                'phone_number' => substr($phone, 0, 20),
                'added_via' => 'csv_upload',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($batch)) {
            // Bulk insert ignoring duplicate key integrity conflicts
            Contact::insertOrIgnore($batch);
        }
    }
}
