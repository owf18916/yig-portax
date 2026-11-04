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
