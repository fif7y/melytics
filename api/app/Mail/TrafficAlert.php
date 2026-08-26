<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrafficAlert extends Mailable
{
    public function __construct(
        public Site $site,
        public string $kind, // 'spike' | 'drop'
        public int $today,
        public int $median,
        public string $asOf, // site-local HH:MM
        public array $topPages,
        public array $topReferrers,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ucfirst($this->kind)." on {$this->site->domain} — {$this->today} visitors so far (~{$this->median} typical)");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.traffic-alert');
    }
}
