import { ref, computed } from 'vue'

/**
 * Composable for form validation and error handling
 */
export const useFormValidation = () => {
    const formErrors = ref({})
    const fieldErrors = ref({})
    const isSubmitting = ref(false)
    
    const hasErrors = computed(() => Object.keys(formErrors.value).length > 0)
    
    /**
     * Validate a single field
     */
    const validateField = (fieldName, value, rules = {}) => {
        const errors = []
        
        // Required validation
        if (rules.required && (!value || (typeof value === 'string' && value.trim() === ''))) {
            errors.push(`${rules.label || fieldName} is required`)
        }
        
        // Min length
        if (rules.minLength && value && value.length < rules.minLength) {
            errors.push(`${rules.label || fieldName} must be at least ${rules.minLength} characters`)
        }
        
        // Max length
        if (rules.maxLength && value && value.length > rules.maxLength) {
            errors.push(`${rules.label || fieldName} must not exceed ${rules.maxLength} characters`)
        }
        
        // Min value (for numbers)
        if (rules.min && value && Number(value) < rules.min) {
            errors.push(`${rules.label || fieldName} must be at least ${rules.min}`)
        }
        
        // Max value (for numbers)
        if (rules.max && value && Number(value) > rules.max) {
            errors.push(`${rules.label || fieldName} must not exceed ${rules.max}`)
        }
        
        // Email validation
        if (rules.email && value && !isValidEmail(value)) {
            errors.push(`${rules.label || fieldName} must be a valid email`)
        }
        
        // Phone validation
        if (rules.phone && value && !isValidPhone(value)) {
            errors.push(`${rules.label || fieldName} must be a valid phone number`)
        }
        
        // Date validation
        if (rules.date && value && !isValidDate(value)) {
            errors.push(`${rules.label || fieldName} must be a valid date`)
        }
        
        // Custom validation
        if (rules.custom && !rules.custom(value)) {
            errors.push(rules.customMessage || `${rules.label || fieldName} is invalid`)
        }
        
        fieldErrors.value[fieldName] = errors
        return errors.length === 0
    }
    
    /**
     * Validate entire form
     */
    const validateForm = (formData, validationRules) => {
        formErrors.value = {}
        let isValid = true
        
        for (const [fieldName, rules] of Object.entries(validationRules)) {
            const value = formData[fieldName]
            if (!validateField(fieldName, value, rules)) {
                formErrors.value[fieldName] = fieldErrors.value[fieldName]
                isValid = false
            }
        }
        
        return isValid
    }
    
    /**
     * Clear all errors
     */
    const clearErrors = () => {
        formErrors.value = {}
        fieldErrors.value = {}
    }
    
    /**
     * Clear specific field error
     */
    const clearFieldError = (fieldName) => {
        delete formErrors.value[fieldName]
        delete fieldErrors.value[fieldName]
    }
    
    /**
     * Handle API validation errors
     */
    const handleValidationError = (error) => {
        if (error.response?.data?.errors) {
            formErrors.value = error.response.data.errors
            for (const [fieldName, messages] of Object.entries(error.response.data.errors)) {
                fieldErrors.value[fieldName] = Array.isArray(messages) ? messages : [messages]
            }
        }
    }
    
    return {
        formErrors,
        fieldErrors,
        isSubmitting,
        hasErrors,
        validateField,
        validateForm,
        clearErrors,
        clearFieldError,
        handleValidationError,
    }
}

/**
 * Email validation
 */
export const isValidEmail = (email) => {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    return re.test(email)
}

/**
 * Phone validation
 */
export const isValidPhone = (phone) => {
    const re = /^[\d\s\-\+\(\)]+$/
    return re.test(phone) && phone.replace(/\D/g, '').length >= 10
}

/**
 * Date validation
 */
export const isValidDate = (dateString) => {
    const date = new Date(dateString)
    return date instanceof Date && !isNaN(date)
}

/**
 * Tax ID validation (Indonesia NPWP format)
 */
export const isValidNPWP = (npwp) => {
    // NPWP format: XX.XXX.XXX.X-XXX.XXX
    const re = /^\d{2}\.\d{3}\.\d{3}\.\d{1}-\d{3}\.\d{3}$/
    return re.test(npwp)
}

/**
 * Amount/currency validation
 */
export const isValidAmount = (amount) => {
    const num = parseFloat(amount)
    return !isNaN(num) && num >= 0
}
