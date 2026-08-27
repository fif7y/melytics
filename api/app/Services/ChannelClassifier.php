<?php

namespace App\Services;

/** Groups referrer hosts into Direct / Search / Social / AI / Email / Referral. */
class ChannelClassifier
{
    /** Referrer-host needles per channel; AI before Search so gemini.google.com lands in AI. */
    private const CHANNEL_HOSTS = [
        'AI' => ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'phind.com', 'poe.com'],
        'Search' => ['google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.com', 'ecosia.org', 'search.brave.com', 'startpage.com', 'baidu.com', 'yandex.'],
        'Social' => ['twitter.com', 'x.com', 't.co', 'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'reddit.com', 'news.ycombinator.com', 'lobste.rs', 'threads.net', 'bsky.app', 'mastodon', 'tiktok.com', 'youtube.com', 'pinterest.'],
        'Email' => ['mail.google.com', 'outlook.live.com', 'mail.yahoo.com', 'mail.proton.me'],
    ];

    public static function classify(?string $host): string
    {
        if (! $host) {
            return 'Direct';
        }
        foreach (self::CHANNEL_HOSTS as $channel => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($host, $needle)) {
                    return $channel;
                }
            }
        }

        return 'Referral';
    }
}
