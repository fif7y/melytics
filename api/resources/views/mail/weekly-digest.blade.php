<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fcfcfb;font-family:-apple-system,'Segoe UI',sans-serif;color:#0b0b0b;">
@php
    $t = $stats['totals'];
    $p = $stats['previous_totals'];
    $delta = $p['visitors'] ? round(($t['visitors'] - $p['visitors']) / $p['visitors'] * 100) : null;
@endphp
<div style="max-width:520px;margin:0 auto;padding:32px 20px;">
    <p style="font-size:14px;color:#8a8984;margin:0 0 4px;">melytics · weekly</p>
    <h1 style="font-size:20px;font-weight:600;margin:0 0 28px;">{{ $site->domain }}</h1>

    <div style="background:#f4f4f1;border-radius:14px;padding:20px 24px;margin-bottom:16px;">
        <table role="presentation" width="100%"><tr>
            <td>
                <div style="font-size:13px;color:#52514e;">Visitors</div>
                <div style="font-size:28px;font-weight:600;">{{ number_format($t['visitors']) }}</div>
            </td>
            <td>
                <div style="font-size:13px;color:#52514e;">Pageviews</div>
                <div style="font-size:28px;font-weight:600;">{{ number_format($t['pageviews']) }}</div>
            </td>
            @if ($delta !== null)
            <td align="right" style="font-size:14px;color:{{ $delta >= 0 ? '#008300' : '#e34948' }};">
                {{ $delta >= 0 ? '↑' : '↓' }} {{ abs($delta) }}%
            </td>
            @endif
        </tr></table>
    </div>

    @foreach ([['Top pages', $topPages], ['Top referrers', $topReferrers]] as [$title, $rows])
        @if (count($rows))
        <div style="background:#f4f4f1;border-radius:14px;padding:20px 24px;margin-bottom:16px;">
            <div style="font-size:13px;color:#52514e;margin-bottom:10px;">{{ $title }}</div>
            @foreach ($rows as $row)
            <table role="presentation" width="100%" style="font-size:14px;line-height:2;"><tr>
                <td style="color:#0b0b0b;">{{ $row->value }}</td>
                <td align="right" style="color:#52514e;">{{ number_format($row->pageviews) }}</td>
            </tr></table>
            @endforeach
        </div>
        @endif
    @endforeach

    @if (count($goals))
    <div style="background:#f4f4f1;border-radius:14px;padding:20px 24px;margin-bottom:16px;">
        <div style="font-size:13px;color:#52514e;margin-bottom:10px;">Goals</div>
        @foreach ($goals as $goal)
        <table role="presentation" width="100%" style="font-size:14px;line-height:2;"><tr>
            <td>{{ $goal['name'] }}</td>
            <td align="right" style="color:#52514e;">{{ $goal['conversions'] }} · {{ $goal['rate'] }}%</td>
        </tr></table>
        @endforeach
    </div>
    @endif

    <p style="font-size:13px;color:#8a8984;margin-top:24px;">
        <a href="{{ config('app.url') }}" style="color:#2a78d6;text-decoration:none;">Open dashboard</a>
    </p>
</div>
</body>
</html>
