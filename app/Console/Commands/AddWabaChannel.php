<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WabaChannel;
use App\Models\Tenant;

class AddWabaChannel extends Command
{
    protected $signature = 'channel:add';
    protected $description = 'Manually add a WhatsApp Business Channel (phone number) to a tenant';

    public function handle()
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $this->error('No tenants found. Please seed the database first using: php artisan db:seed');
            return 1;
        }

        $tenantNames = $tenants->pluck('name', 'id')->toArray();
        $selectedName = $this->choice('Select the Tenant:', $tenantNames);
        $tenantId = array_search($selectedName, $tenantNames);

        $displayName = $this->ask('Enter Display Name (e.g., Click & Pick Main):');
        $phoneNumber = $this->ask('Enter Phone Number (e.g., +96171417539):');
        $phoneNumberId = $this->ask('Enter Meta Phone Number ID:');
        $wabaId = $this->ask('Enter Meta WABA ID:');
        $accessToken = $this->secret('Enter Meta System User Access Token (input is hidden):');

        if (!$accessToken) {
            $this->error('Access token is required.');
            return 1;
        }

        // Disable tenant scoping for this command so we can write across tenants
        WabaChannel::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId,
            'display_name' => $displayName,
            'phone_number' => $phoneNumber,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'access_token' => $accessToken, // Mutator encrypts this automatically
            'is_active' => true,
            'connected_at' => now(),
        ]);

        $this->info('Channel connection saved successfully!');
        return 0;
    }
}
