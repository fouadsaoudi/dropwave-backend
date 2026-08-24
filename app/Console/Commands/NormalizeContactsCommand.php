<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\PhoneService;
use Illuminate\Support\Facades\DB;

class NormalizeContactsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contacts:normalize';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize all contact phone numbers to standardized E.164 (+961...) format and merge duplicates';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting contact phone numbers normalization...');

        $contacts = Contact::withoutGlobalScopes()->get();
        $this->info("Found {$contacts->count()} contacts in database.");

        $updatedCount = 0;
        $mergedCount = 0;
        $unchangedCount = 0;

        DB::transaction(function () use ($contacts, &$updatedCount, &$mergedCount, &$unchangedCount) {
            foreach ($contacts as $contact) {
                // If contact was already deleted in a merge step, skip
                if (!Contact::withoutGlobalScopes()->where('id', $contact->id)->exists()) {
                    continue;
                }

                $normalized = PhoneService::normalize($contact->phone_number);

                if (empty($normalized) || $normalized === $contact->phone_number) {
                    $unchangedCount++;
                    continue;
                }

                // Check if another contact with this normalized number already exists in the same tenant
                $existing = Contact::withoutGlobalScopes()
                    ->where('tenant_id', $contact->tenant_id)
                    ->where('phone_number', $normalized)
                    ->where('id', '!=', $contact->id)
                    ->first();

                if ($existing) {
                    // Re-link conversations to the existing contact
                    Conversation::withoutGlobalScopes()
                        ->where('contact_id', $contact->id)
                        ->update(['contact_id' => $existing->id]);

                    // Preserve the better name
                    if ((empty($existing->name) || $existing->name === $existing->phone_number) && !empty($contact->name) && $contact->name !== $contact->phone_number) {
                        $existing->update(['name' => $contact->name]);
                    }

                    $contact->delete();
                    $mergedCount++;
                } else {
                    $contact->update(['phone_number' => $normalized]);
                    $updatedCount++;
                }
            }
        });

        $this->info("Normalization complete!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Contacts Processed', $contacts->count()],
                ['Updated to E.164 (+961...) format', $updatedCount],
                ['Duplicates Merged & Re-linked', $mergedCount],
                ['Already Normalized / Unchanged', $unchangedCount],
            ]
        );

        return Command::SUCCESS;
    }
}
