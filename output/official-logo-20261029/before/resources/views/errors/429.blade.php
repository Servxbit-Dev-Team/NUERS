@php
    $headers = isset($exception) && method_exists($exception, 'getHeaders') ? $exception->getHeaders() : [];
    $rawRetry = $retryAfter ?? $headers['Retry-After'] ?? null;
    $seconds = is_numeric($rawRetry) ? (int) $rawRetry : ($rawRetry ? strtotime($rawRetry) - time() : 0);
    $seconds = max(0, min(86400, $seconds));
    $prefix = realpath((string) request()->server('DOCUMENT_ROOT', '')) === realpath(base_path()) ? '/public' : '';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow"><title>Please pause and try again | NUERS</title>
    <link rel="icon" type="image/x-icon" href="{{ $prefix }}/favicon.ico?v=b50631c79afb" />
    <link rel="icon" type="image/png" sizes="48x48" href="{{ $prefix }}/nuers-favicon.png?v=b50631c79afb" />
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $prefix }}/apple-touch-icon.png?v=b50631c79afb" />
    <link rel="manifest" href="{{ $prefix }}/site.webmanifest.json?v=b50631c79afb" />
    <meta name="theme-color" content="#062344" />
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f2f7ff;color:#102e53;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;display:flex;flex-direction:column}header{background:#062344;padding:23px 7%;display:flex;justify-content:space-between;align-items:center;gap:20px}header img{width:194px;height:auto}header span{color:#c7daee;font-size:13px;letter-spacing:.04em}.brand-line{height:4px;background:linear-gradient(90deg,#00a6ff 0 33%,#ffd000 33% 66%,#ed1635 66%)}main{width:min(960px,calc(100% - 40px));margin:auto;padding:60px 0}.card{display:grid;grid-template-columns:310px 1fr;background:#fff;box-shadow:0 20px 60px #12345c0c;border:1px solid #dce7f5;border-radius:18px;overflow:hidden}.aside{background:linear-gradient(155deg,#0d3d70,#061e3a);padding:42px 30px;color:#fff;display:flex;flex-direction:column;justify-content:space-between;gap:50px}.eyebrow{font-size:11px;letter-spacing:.16em;font-weight:700;text-transform:uppercase;color:#a9cbea}.aside h2{font-size:27px;line-height:1.2;margin:18px 0 14px;letter-spacing:-.02em}.aside p{font-size:14px;line-height:1.7;color:#c5d8ed}.protected{border-top:1px solid #35516e;padding-top:20px;font-size:12px;line-height:1.7;color:#c5d8ed}.content{padding:42px}.code{font-size:12px;color:#58708d;font-weight:600;letter-spacing:.08em}.icon{display:flex;align-items:center;justify-content:center;width:60px;height:60px;background:#edf5ff;border:1px solid #d7e8ff;border-radius:15px;margin:20px 0;color:#0056d6}.icon svg{width:30px;height:30px}h1{font-size:30px;line-height:1.2;letter-spacing:-.035em;margin:0 0 14px;color:#0f2d5b}.intro{font-size:15px;line-height:1.75;color:#536b89;margin:0}.retry{padding:18px 20px;border:1px solid #d6e6ff;border-radius:10px;background:#f4f8ff;margin:24px 0}.retry strong{display:block;color:#0056d6;font-size:18px;margin-bottom:5px}.retry p{font-size:13px;line-height:1.65;margin:0;color:#536b89}.actions{display:flex;flex-wrap:wrap;gap:10px;margin:22px 0}.actions a{padding:12px 17px;text-decoration:none;border:1px solid #c7dafa;border-radius:7px;font-size:14px;font-weight:600;color:#0056d6;display:inline-flex;align-items:center;justify-content:center}.actions .primary{background:#0056d6;color:#fff;border-color:#0056d6}.help{padding-top:20px;border-top:1px solid #e8eef7;color:#677d97;font-size:12px;line-height:1.7}.help strong{color:#344f71}footer{padding:22px;text-align:center;font-size:12px;color:#657c98;line-height:1.8}a:focus-visible{outline:3px solid #00a6ff;outline-offset:4px}@media(max-width:700px){header{padding:20px}header img{width:156px}header span{font-size:11px}.card{grid-template-columns:1fr}.aside{padding:25px;gap:12px}.aside h2{font-size:22px;margin:12px 0}.aside p,.protected{display:none}.content{padding:28px 24px}.icon{margin-top:18px}h1{font-size:27px}main{padding:28px 0}.actions a{flex:1}}
    </style>
    <script defer src="{{ $prefix }}/error-assets/retry-countdown.js"></script>
</head>
<body>
<header><a href="/" aria-label="NUERS home"><img src="{{ $prefix }}/auth-assets/nuers-digital-white.png" alt="NUERS — National Unified Electronic Receipt System"></a><span>SECURE PLATFORM ACCESS</span></header><div class="brand-line"></div>
<main><div class="card">
    <aside class="aside"><div><div class="eyebrow">NUERS access protection</div><h2>A brief pause.<br>A reliable connection.</h2><p>Request limits help keep NUERS responsive and protect access to your business records.</p></div><div class="protected">Digital transactions.<br>A stronger Philippines.</div></aside>
    <section class="content" aria-labelledby="title"><div class="code">HTTP 429 · TOO MANY REQUESTS</div>
        <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg></div>
        <h1 id="title">Please pause and try again</h1>
        <p class="intro">Too many requests were received for this action or network. Wait before repeating the action so your next request can be processed.</p>
        <div class="retry"><strong id="retry-countdown" data-seconds="{{ $seconds }}">{{ $seconds ? 'Try again in '.$seconds.' seconds' : 'Please try again shortly' }}</strong><p id="retry-guidance">Avoid repeated refreshes or submissions during this pause. The page will not automatically repeat your action.</p></div>
        <div class="actions"><a class="primary" href="/login">Return to sign in</a><a href="/">NUERS home</a></div>
        <div class="help"><strong>Submitting a sale or payment?</strong> Check its NUERS status before retrying. Keep the same event reference to prevent duplicate submissions. If this continues, contact your administrator with the time and “HTTP 429.”</div>
    </section>
</div></main>
<footer>NUERS · National Unified Electronic Receipt System<br>Secure access · Reliable records · Connected businesses</footer>
</body>
</html>
