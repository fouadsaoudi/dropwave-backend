<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ContactCategory;
use App\Models\Contact;
use App\Models\OptOut;
use App\Jobs\SendCampaignMessageJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $campaigns = Campaign::with(['channel', 'template'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($campaigns);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'channel_id' => 'required|exists:waba_channels,id',
            'template_id' => 'required|exists:message_templates,id',
            'category_id' => 'nullable|exists:contact_categories,id',
            'variables' => 'nullable|array', // Default variables values for all recipients
        ]);

        $tenantId = Auth::user()->tenant_id;

        // Resolve contacts list
        if ($request->category_id) {
            $category = ContactCategory::findOrFail($request->category_id);
            $contacts = $category->contacts()->get();
        } else {
            // Target all contacts for this tenant
            $contacts = Contact::where('tenant_id', $tenantId)->get();
        }

        if ($contacts->isEmpty()) {
            return response()->json([
                'error' => 'no_recipients',
                'message' => 'The selected contact category does not have any contacts mapped to it.'
            ], 422);
        }

        $campaign = DB::transaction(function () use ($request, $tenantId, $contacts) {
            $campaign = Campaign::create([
                'tenant_id' => $tenantId,
                'channel_id' => $request->channel_id,
                'template_id' => $request->template_id,
                'name' => $request->name,
                'status' => 'draft',
                'total_recipients' => $contacts->count(),
            ]);

            foreach ($contacts as $contact) {
                CampaignRecipient::create([
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'phone_number' => $contact->phone_number,
                    'variables' => $request->variables ?? [],
                    'status' => 'pending',
                ]);
            }

            return $campaign;
        });

        return response()->json($campaign->load(['channel', 'template']), 201);
    }

    public function show($id)
    {
        $campaign = Campaign::with(['channel', 'template'])->findOrFail($id);
        
        // Paginate recipients to avoid massive payload response
        $recipients = $campaign->recipients()
            ->with('contact:id,name')
            ->orderBy('id', 'asc')
            ->paginate(50);

        return response()->json([
            'campaign' => $campaign,
            'recipients' => $recipients,
        ]);
    }

    public function destroy($id)
    {
        $campaign = Campaign::findOrFail($id);
        
        if ($campaign->status === 'sending') {
            return response()->json([
                'error' => 'locked',
                'message' => 'Cannot delete a campaign that is currently actively sending.'
            ], 422);
        }

        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted successfully.']);
    }

    /**
     * Start campaign sending dispatch loop.
     */
    public function start($id)
    {
        $campaign = Campaign::findOrFail($id);

        if ($campaign->status !== 'draft') {
            return response()->json([
                'error' => 'invalid_state',
                'message' => 'Only draft campaigns can be started.'
            ], 422);
        }

        $recipients = $campaign->recipients()->where('status', 'pending')->get();

        if ($recipients->isEmpty()) {
            $campaign->update([
                'status' => 'completed',
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Campaign has no pending recipients and was marked as completed.',
                'campaign' => $campaign,
            ]);
        }

        DB::transaction(function () use ($campaign) {
            $campaign->update([
                'status' => 'sending',
                'started_at' => now(),
            ]);
        });

        // Dispatch jobs staggered (delay by 100ms multiplier to avoid bursting and hit Meta rate limits cleanly)
        foreach ($recipients as $index => $recipient) {
            SendCampaignMessageJob::dispatch($recipient->id)
                ->delay(now()->addMilliseconds($index * 150));
        }

        return response()->json([
            'message' => 'Campaign queued for sending.',
            'campaign' => $campaign->load(['channel', 'template']),
        ]);
    }

    /**
     * List all opt-outs (contacts who blocked the number).
     */
    public function optOutsIndex(Request $request)
    {
        $optOuts = OptOut::orderBy('opted_out_at', 'desc')->get();
        return response()->json($optOuts);
    }
}
