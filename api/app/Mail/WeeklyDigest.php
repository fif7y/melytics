<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WeeklyDigest extends Mailable
{
    public function __construct(
        public Site $site,
        public array $stats,
        public array $topPages,
        public array $topReferrers,
        public array $goals,
    ) {}

    public function envelope(): Envelope
    {
        $v = number_format($this->stats['totals']['visitors']);

        return new Envelope(subject: "{$this->site->domain} — {$v} visitors this week");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.weekly-digest');
    }
}
