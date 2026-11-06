<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>KIAN submission required</title></head>
<body style="margin:0; padding:0; background-color:#f1f5f9; color:#1e293b; font-family:Arial,Helvetica,sans-serif; line-height:1.6;">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">Action required: review the remaining company tax exposure and prepare KIAN.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;"><tr><td align="center" style="padding:16px 8px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:#ffffff; table-layout:fixed; overflow-wrap:break-word;">
<tr><td bgcolor="#1e40af" style="padding:24px; color:#ffffff;"><p style="margin:0; font-size:14px;">PorTax | Tax Case Management</p><h1 style="margin:8px 0 0; font-size:24px; line-height:1.3;">KIAN submission required</h1></td></tr>
<tr><td style="padding:20px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7"><tr><td style="padding:16px; color:#78350f;"><h2 style="margin:0 0 8px; font-size:16px;">Action Required</h2><p style="margin:0;">The committed {{ $stageName ?? 'decision stage' }} result shows remaining company tax exposure. Please review the case and prepare the required KIAN submission and supporting documents.</p></td></tr></table>
<h2 style="font-size:18px; margin:24px 0 12px;">Case Information</h2>
<table width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse; font-size:14px;">
@foreach (['Case Number' => $caseNumber ?? 'Not available', 'Case Type' => $caseType ?? 'Not available', 'Tax Year' => $taxYear ?? 'Not available', 'Taxpayer' => $entityName ?? 'Not available', 'Current Stage' => 'Stage '.($stage ?? 'not available').' - '.($stageName ?? 'Decision stage')] as $label => $value)
<tr><th scope="row" align="left" width="40%" style="border-bottom:1px solid #e2e8f0; vertical-align:top;">{{ $label }}</th><td style="border-bottom:1px solid #e2e8f0;">{{ $value }}</td></tr>
@endforeach
</table>
</td></tr>
<tr><td style="padding:0 20px 20px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eff6ff"><tr><td style="padding:16px; border-left:4px solid #2563eb;"><h2 style="margin:0 0 8px; font-size:16px;">Remaining Company Tax Exposure</h2><p style="margin:0; font-size:24px; font-weight:bold; color:#1e40af;">{{ $currency ?? 'Currency not available' }} {{ $amount ?? 'Not available' }}</p><p style="margin:8px 0 0; font-size:13px;">This reminder records the exposure at the committed decision event. Review the case for its current status.</p></td></tr></table></td></tr>
<tr><td style="padding:0 20px 20px;"><h2 style="font-size:18px; margin:0 0 8px;">Next Steps</h2><ol style="padding-left:24px; margin:0;"><li>Open the tax case and sign in with your authorized account.</li><li>Review the {{ $stageName ?? 'decision stage' }} evidence and remaining company tax exposure.</li><li>Complete the KIAN details and submit the required supporting documents in PorTax.</li></ol></td></tr>
<tr><td align="center" style="padding:0 20px 24px;">
@if (!empty($caseUrl))
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#1e40af" style="padding:12px 24px;"><a href="{{ $caseUrl }}" style="color:#ffffff; font-size:16px; font-weight:bold; text-decoration:underline;">View Tax Case</a></td></tr></table>
<p style="font-size:12px; text-align:left; word-break:break-all;">If the button does not work, copy this address into your browser:<br><a href="{{ $caseUrl }}" style="color:#1e40af;">{{ $caseUrl }}</a></p>
@else
<p>The case link is not available. Open PorTax and find the case using the case number above.</p>
@endif
</td></tr>
<tr><td bgcolor="#f8fafc" style="padding:20px; border-top:1px solid #e2e8f0; font-size:12px; color:#475569;"><p style="margin:0 0 8px;"><strong>Note:</strong> This is an automated notification from PorTax. For assistance, contact your tax administration team.</p><p style="margin:0;">You received this email because you are associated with this tax case.</p><p style="margin:8px 0 0;">PorTax Tax Case Management System. All rights reserved.</p></td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body></html>
