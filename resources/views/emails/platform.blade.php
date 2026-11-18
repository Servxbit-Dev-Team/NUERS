<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;background:#f2f6fc;color:#152e52;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6">
<div style="display:none;max-height:0;overflow:hidden">{{ $subject }} — National Unified Electronic Receipt System</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:32px 12px">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #dce5f2;border-radius:12px;overflow:hidden">
<tr><td style="padding:26px 32px;background:#061e42;color:#fff"><div style="font-size:32px;font-weight:bold;letter-spacing:2px">NUERS</div><div style="font-size:11px;letter-spacing:1px">NATIONAL UNIFIED ELECTRONIC RECEIPT SYSTEM</div></td></tr>
<tr><td style="height:4px;background:linear-gradient(to right,#0879ff 0%,#0879ff 33%,#ffcf00 33%,#ffcf00 66%,#ec1b31 66%)"></td></tr>
<tr><td style="padding:32px"><h1 style="margin:0 0 20px;font-size:23px;line-height:1.3;color:#082b60">{{ $subject }}</h1>
@foreach(explode("\n", $body) as $line)
@if(trim($line)!=='')<p style="margin:0 0 14px;overflow-wrap:anywhere">{{ $line }}</p>@endif
@endforeach
@if(!empty($actionUrl))<p style="margin:28px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#005aeb;color:white;text-decoration:none;padding:12px 22px;border-radius:7px;font-weight:bold">{{ $actionLabel ?? 'Open NUERS' }}</a></p><p style="font-size:12px;color:#607391;word-break:break-all">If the button does not work, copy this address into your browser:<br>{{ $actionUrl }}</p>@endif
<hr style="border:0;border-top:1px solid #e3eaf4;margin:28px 0"><p style="font-size:13px;color:#607391;margin:0">For account assistance, open Help &amp; Support in NUERS. Never share your password, verification code, recovery codes or API credentials by email.</p>
</td></tr><tr><td style="padding:20px 32px;background:#f6f9fe;font-size:12px;color:#607391">© {{ date('Y') }} NUERS. All rights reserved.<br>Digital Transactions. A Stronger Philippines.<br>Automated transactional notification. Please use NUERS support for assistance.</td></tr>
</table></td></tr></table></body></html>
