<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fcfcfb;font-family:-apple-system,'Segoe UI',sans-serif;color:#0b0b0b;">
@php
    $up = $kind === 'spike';
    $color = $up ? '#008300' : '#e34948';
    $factor = $median ? round($today / max($median, 1), 1) : null;
@endphp
<div style="max-width:520px;margin:0 auto;padding:32px 20px;">
    <p style="font-size:14px;color:#8a8984;margin:0 0 4px;">melytics · alert</p>
    <h1 style="font-size:20px;font-weight:600;margin:0 0 28px;">{{ $site->domain }}</h1>

    <div style="background:#f4f4f1;border-radius:14px;padding:20px 24px;margin-bottom:16px;">
        <div style="font-size:13px;color:#52514e;">Traffic {{ $kind }} — by {{ $asOf }}</div>
        <div style="font-size:32px;font-weight:600;margin:2px 0 4px;">
            {{ number_format($today) }} <span style="font-size:15px;font-weight:400;color:#52514e;">visitors</span>
            <span style="font-size:15px;color:{{ $color }};">{{ $up ? '↑' : '↓' }}{{ $factor ? " {$factor}×" : '' }}</span>
        </div>
        <div style="font-size:13px;color:#52514e;">A typical day has ~{{ number_format($median) }} by this time (7-day median, same window).</div>
    </div>

    @foreach ([[$up ? 'Where it’s coming from' : 'Top referrers today', $topReferrers], ['What they’re reading', $topPages]] as [$title, $rows])
        @if (count($rows))
        <div style="background:#f4f4f1;border-radius:14px;padding:20px 24px;margin-bottom:16px;">
            <div style="font-size:13px;color:#52514e;margin-bottom:10px;">{{ $title }}</div>
            @foreach ($rows as $row)
            <table role="presentation" width="100%" style="font-size:14px;line-height:2;"><tr>
                <td style="color:#0b0b0b;">{{ $row->value ?: 'Direct' }}</td>
                <td align="right" style="color:#52514e;">{{ number_format($row->visitors) }}</td>
            </tr></table>
            @endforeach
        </div>
        @endif
    @endforeach

    <p style="font-size:13px;color:#8a8984;margin-top:24px;">
        <a href="https://stats.fif7y.com" style="color:#2a78d6;text-decoration:none;">Open dashboard</a>
        · You get at most one of these per day.
    </p>
</div>
</body>
</html>
