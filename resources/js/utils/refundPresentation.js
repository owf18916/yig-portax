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
  if (!/^\d+(?:\.\d{1,2})?$/.test(text)) return [currencyCode, text].filter(Boolean).join(' ')
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
