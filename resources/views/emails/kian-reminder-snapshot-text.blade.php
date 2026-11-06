PorTax | Tax Case Management
KIAN submission required

Action Required
The committed {{ $stageName ?? 'decision stage' }} result shows remaining company tax exposure. Please review the case and prepare the required KIAN submission and supporting documents.

Case Information
Case Number: {{ $caseNumber ?? 'Not available' }}
Case Type: {{ $caseType ?? 'Not available' }}
Tax Year: {{ $taxYear ?? 'Not available' }}
Taxpayer: {{ $entityName ?? 'Not available' }}
Current Stage: Stage {{ $stage ?? 'not available' }} - {{ $stageName ?? 'Decision stage' }}

Remaining Company Tax Exposure: {{ $currency ?? 'Currency not available' }} {{ $amount ?? 'Not available' }}
This reminder records the exposure at the committed decision event. Review the case for its current status.

Next Steps
1. Open the tax case and sign in with your authorized account.
2. Review the {{ $stageName ?? 'decision stage' }} evidence and remaining company tax exposure.
3. Complete the KIAN details and submit the required supporting documents in PorTax.

@if (!empty($caseUrl))
View Tax Case: {{ $caseUrl }}
If the button does not work, copy this address into your browser.
@else
The case link is not available. Open PorTax and find the case using the case number above.
@endif

Note: This is an automated notification from PorTax. For assistance, contact your tax administration team.
You received this email because you are associated with this tax case.
PorTax Tax Case Management System. All rights reserved.
