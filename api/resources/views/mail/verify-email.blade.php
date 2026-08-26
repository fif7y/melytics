<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fcfcfb;font-family:-apple-system,'Segoe UI',sans-serif;color:#0b0b0b;">
<div style="max-width:520px;margin:0 auto;padding:32px 20px;">
    <p style="font-size:14px;color:#8a8984;margin:0 0 4px;">melytics</p>
    <h1 style="font-size:20px;font-weight:600;margin:0 0 28px;">Verify your email</h1>

    <div style="background:#f4f4f1;border-radius:14px;padding:24px;margin-bottom:16px;">
        <p style="font-size:14px;margin:0 0 16px;">Hi {{ $user->name }} — one click and your account is ready.</p>
        <a href="{{ $url }}" style="display:inline-block;background:#0b0b0b;color:#fcfcfb;font-size:14px;font-weight:500;padding:10px 18px;border-radius:10px;text-decoration:none;">Verify email</a>
    </div>

    <p style="font-size:13px;color:#8a8984;margin-top:24px;">
        The link is valid for 24 hours. If you didn't create this account, ignore this email.
    </p>
</div>
</body>
</html>
