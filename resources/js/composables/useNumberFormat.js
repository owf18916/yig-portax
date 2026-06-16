const DEFAULT_OPTIONS = {
  thousandSeparator: ',',
  decimalSeparator: '.',
  decimalPlaces: null,
  integerOnly: false,
  allowNegative: false,
  min: null,
  max: null
}

const escapeRegExp = (value) => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

export const normalizeNumberInput = (value, options = {}) => {
  const opts = { ...DEFAULT_OPTIONS, ...options }

  if (value === null || value === undefined || value === '') {
    return ''
  }

  let input = String(value).trim()
  if (!input) return ''

  const isNegative = opts.allowNegative && input.startsWith('-')

  if (opts.thousandSeparator) {
    input = input.replace(new RegExp(escapeRegExp(opts.thousandSeparator), 'g'), '')
  }

  if (opts.decimalSeparator !== '.') {
    input = input.replace(new RegExp(escapeRegExp(opts.decimalSeparator), 'g'), '.')
  }

  let output = ''
  let hasDecimal = false

  for (const char of input) {
    if (/\d/.test(char)) {
      output += char
    } else if (!opts.integerOnly && char === '.' && !hasDecimal) {
      output += '.'
      hasDecimal = true
    }
  }

  if (opts.decimalPlaces !== null && opts.decimalPlaces !== undefined && output.includes('.')) {
    const [integerPart, decimalPart = ''] = output.split('.')
    output = `${integerPart}.${decimalPart.slice(0, Number(opts.decimalPlaces))}`
  }

  if (isNegative && output === '') {
    return '-'
  }

  if (isNegative) {
    output = `-${output}`
  }

  if (output === '-0') return '0'

  return output
}

export const formatNumberInput = (value, options = {}) => {
  const opts = { ...DEFAULT_OPTIONS, ...options }
  const normalized = normalizeNumberInput(value, opts)

  if (normalized === '') return ''
  if (normalized === '-') return normalized

  const negative = normalized.startsWith('-')
  const unsigned = negative ? normalized.slice(1) : normalized
  const [integerPart = '', decimalPart] = unsigned.split('.')
  const groupedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, opts.thousandSeparator)
  const formatted = decimalPart !== undefined
    ? `${groupedInteger}${opts.decimalSeparator}${decimalPart}`
    : groupedInteger

  return `${negative ? '-' : ''}${formatted}`
}

export const coerceNumberBounds = (value, options = {}) => {
  const opts = { ...DEFAULT_OPTIONS, ...options }
  if (value === '') return ''

  const numberValue = Number(value)
  if (Number.isNaN(numberValue)) return value

  if (opts.min !== null && opts.min !== undefined && numberValue < Number(opts.min)) {
    return String(opts.min)
  }

  if (opts.max !== null && opts.max !== undefined && numberValue > Number(opts.max)) {
    return String(opts.max)
  }

  return value
}

export const countNumberCharacters = (value, options = {}) => {
  const opts = { ...DEFAULT_OPTIONS, ...options }
  const decimalSeparator = escapeRegExp(opts.decimalSeparator)
  const pattern = opts.allowNegative
    ? new RegExp(`[0-9${decimalSeparator}-]`, 'g')
    : new RegExp(`[0-9${decimalSeparator}]`, 'g')

  return (String(value).match(pattern) || []).length
}

export const findCaretFromNumberCharacterCount = (value, targetCount, options = {}) => {
  if (targetCount <= 0) return 0

  const opts = { ...DEFAULT_OPTIONS, ...options }
  const decimalSeparator = opts.decimalSeparator
  let count = 0

  for (let index = 0; index < value.length; index += 1) {
    const char = value[index]
    const isNumberChar = /\d/.test(char) || char === decimalSeparator || (opts.allowNegative && char === '-')

    if (isNumberChar) {
      count += 1
      if (count >= targetCount) {
        return index + 1
      }
    }
  }

  return value.length
}
