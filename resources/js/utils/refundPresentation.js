// Existing Refund list labels, shared with the completed receipt summary.
export const formatRefundMethod = (method) => {
  const methodMap = {
    'BANK_TRANSFER': 'Bank Transfer',
    'CHEQUE': 'Cheque',
    'CASH': 'Cash',
    'bank_transfer': 'Bank Transfer',
    'check': 'Cheque',
    'credit': 'Credit',
  }
  return Object.hasOwn(methodMap, method) ? methodMap[method] : method
}

// Decimal string formatting keeps large integral digits and cents lossless.
export const formatRefundAmount = (value, currencyCode = '') => {
  const text = String(value ?? '0')
  if (!/^-?\d+(?:\.\d{1,2})?$/.test(text)) return [currencyCode, text].filter(Boolean).join(' ')
  const [whole, fraction = ''] = text.split('.')
  return [currencyCode, `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction.padEnd(2, '0')}`].filter(Boolean).join(' ')
}

export const refundAmountError = (value, required) => {
  const text = String(value ?? '')
  if (!text && !required) return ''
  if (!/^\d{1,18}(?:\.\d{1,2})?$/.test(text)) return 'Enter a decimal amount with up to 18 integral digits and two decimal places.'
  if (required && !/[1-9]/.test(text)) return 'Refund Amount must be greater than zero.'
  return ''
}

// Informational documentary difference, calculated without floating point or FX.
export const refundAmountDifference = (current, historical, currencyCode = '') => {
  const cents = value => {
    const text = String(value ?? '')
    if (!/^\d{1,18}(?:\.\d{1,2})?$/.test(text)) return null
    const [whole, fraction = ''] = text.split('.')
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
  }
  const a = cents(current), b = cents(historical)
  if (a === null || b === null) return null
  const difference = a - b
  const absolute = difference < 0n ? -difference : difference
  return `${difference < 0n ? '-' : ''}${formatRefundAmount(`${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`, currencyCode)}`
}

// Only the canonical generated reference encodes origin; retain all other business references.
export const refundOriginDescription = reference => {
  const match = /^DECISION-(4|7|10|12)-\d+-\d+$/.exec(String(reference ?? ''))
  const origins = { 4: 'SKP', 7: 'Objection', 10: 'Appeal', 12: 'Supreme Court' }
  return match ? `Refund from ${origins[match[1]]} Decision (Stage ${match[1]})` : reference
}
