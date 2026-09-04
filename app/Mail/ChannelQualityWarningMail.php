<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\WabaChannel;

class ChannelQualityWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public WabaChannel $channel;
    public string $oldRating;
    public string $newRating;

    public function __construct(WabaChannel $channel, string $oldRating, string $newRating)
    {
        $this->channel = $channel;
        $this->oldRating = $oldRating;
        $this->newRating = $newRating;
    }

    public function build()
    {
        $color = $this->newRating === 'RED' ? 'red' : 'orange';
        $tenantName = $this->channel->tenant ? $this->channel->tenant->name : 'N/A';

        return $this->subject("⚠️ WhatsApp Channel Quality Degraded: {$this->channel->display_name}")
            ->html("
                <h2>WhatsApp Channel Quality Alert</h2>
                <p>Hello Administrator,</p>
                <p>This is an automated notification that a WhatsApp Business Channel's quality rating has changed.</p>
                <hr>
                <p><strong>Tenant:</strong> {$tenantName}</p>
                <p><strong>Channel Name:</strong> {$this->channel->display_name}</p>
                <p><strong>Phone Number:</strong> {$this->channel->phone_number}</p>
                <p><strong>Old Quality Rating:</strong> <span style='color: green;'>{$this->oldRating}</span></p>
                <p><strong>New Quality Rating:</strong> <span style='color: {$color}; font-weight: bold;'>{$this->newRating}</span></p>
                <hr>
                <p>Please log in to your admin panel to follow up with this tenant.</p>
            ");
    }
}
