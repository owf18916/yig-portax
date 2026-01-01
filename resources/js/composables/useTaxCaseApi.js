import axios from 'axios'

const API_BASE_URL = '/api'

// Create axios instance
const api = axios.create({
    baseURL: API_BASE_URL,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
})

// Add CSRF token to requests
api.interceptors.request.use((config) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    if (token) {
        config.headers['X-CSRF-TOKEN'] = token
    }
    return config
})

/**
 * API Service for all tax case operations
 */
export const useTaxCaseApi = () => {
    return {
        // ============== TAX CASES ==============
        getTaxCases: async (params = {}) => {
            const { data } = await api.get('/tax-cases', { params })
            return data
        },
        
        getTaxCase: async (id) => {
            const { data } = await api.get(`/tax-cases/${id}`)
            return data
        },
        
        createTaxCase: async (payload) => {
            const { data } = await api.post('/tax-cases', payload)
            return data
        },
        
        updateTaxCase: async (id, payload) => {
            const { data } = await api.put(`/tax-cases/${id}`, payload)
            return data
        },
        
        completeTaxCase: async (id, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${id}/complete`, payload)
            return data
        },
        
        getWorkflowHistory: async (id) => {
            const { data } = await api.get(`/tax-cases/${id}/workflow-history`)
            return data
        },
        
        getDocuments: async (id) => {
            const { data } = await api.get(`/tax-cases/${id}/documents`)
            return data
        },
        
        // ============== SP2 RECORDS ==============
        createSp2: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/sp2-records`, payload)
            return data
        },
        
        getSp2: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/sp2-records`)
            return data
        },
        
        approveSp2: async (caseId, recordId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/sp2-records/${recordId}/approve`, payload)
            return data
        },
        
        rejectSp2: async (caseId, recordId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/sp2-records/${recordId}/reject`, payload)
            return data
        },
        
        // ============== SPHP RECORDS ==============
        createSphp: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/sphp-records`, payload)
            return data
        },
        
        getSphp: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/sphp-records`)
            return data
        },
        
        approveSphp: async (caseId, recordId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/sphp-records/${recordId}/approve`, payload)
            return data
        },
        
        rejectSphp: async (caseId, recordId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/sphp-records/${recordId}/reject`, payload)
            return data
        },
        
        // ============== SKP RECORDS ==============
        createSkp: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/skp-records`, payload)
            return data
        },
        
        getSkp: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/skp-records`)
            return data
        },
        
        approveSkp: async (caseId, recordId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/skp-records/${recordId}/approve`, payload)
            return data
        },
        
        // ============== OBJECTION SUBMISSIONS ==============
        createObjectionSubmission: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/objection-submissions`, payload)
            return data
        },
        
        getObjectionSubmission: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/objection-submissions`)
            return data
        },
        
        submitObjection: async (caseId, submissionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/objection-submissions/${submissionId}/submit`, payload)
            return data
        },
        
        withdrawObjection: async (caseId, submissionId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/objection-submissions/${submissionId}/withdraw`, payload)
            return data
        },
        
        // ============== SPUH RECORDS ==============
        createSpuh: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/spuh-records`, payload)
            return data
        },
        
        getSpuh: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/spuh-records`)
            return data
        },
        
        approveSpuh: async (caseId, recordId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/spuh-records/${recordId}/approve`, payload)
            return data
        },
        
        // ============== OBJECTION DECISIONS ==============
        createObjectionDecision: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/objection-decisions`, payload)
            return data
        },
        
        getObjectionDecision: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/objection-decisions`)
            return data
        },
        
        approveObjectionDecision: async (caseId, decisionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/objection-decisions/${decisionId}/approve`, payload)
            return data
        },
        
        // ============== APPEAL SUBMISSIONS ==============
        createAppealSubmission: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/appeal-submissions`, payload)
            return data
        },
        
        getAppealSubmission: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/appeal-submissions`)
            return data
        },
        
        submitAppeal: async (caseId, submissionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/appeal-submissions/${submissionId}/submit`, payload)
            return data
        },
        
        withdrawAppeal: async (caseId, submissionId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/appeal-submissions/${submissionId}/withdraw`, payload)
            return data
        },
        
        // ============== APPEAL EXPLANATIONS ==============
        createAppealExplanation: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/appeal-explanation-requests`, payload)
            return data
        },
        
        getAppealExplanation: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/appeal-explanation-requests`)
            return data
        },
        
        submitAppealExplanation: async (caseId, requestId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/appeal-explanation-requests/${requestId}/submit`, payload)
            return data
        },
        
        // ============== APPEAL DECISIONS ==============
        createAppealDecision: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/appeal-decisions`, payload)
            return data
        },
        
        getAppealDecision: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/appeal-decisions`)
            return data
        },
        
        approveAppealDecision: async (caseId, decisionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/appeal-decisions/${decisionId}/approve`, payload)
            return data
        },
        
        // ============== SUPREME COURT SUBMISSIONS ==============
        createSupremeCourtSubmission: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/supreme-court-submissions`, payload)
            return data
        },
        
        getSupremeCourtSubmission: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/supreme-court-submissions`)
            return data
        },
        
        submitSupremeCourt: async (caseId, submissionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/supreme-court-submissions/${submissionId}/submit`, payload)
            return data
        },
        
        withdrawSupremeCourt: async (caseId, submissionId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/supreme-court-submissions/${submissionId}/withdraw`, payload)
            return data
        },
        
        // ============== SUPREME COURT DECISIONS ==============
        createSupremeCourtDecision: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/supreme-court-decisions`, payload)
            return data
        },
        
        getSupremeCourtDecision: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/supreme-court-decisions`)
            return data
        },
        
        approveSupremeCourtDecision: async (caseId, decisionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/supreme-court-decisions/${decisionId}/approve`, payload)
            return data
        },
        
        // ============== REFUND PROCESSES ==============
        createRefundProcess: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/refund-processes`, payload)
            return data
        },
        
        getRefundProcess: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/refund-processes`)
            return data
        },
        
        approveRefundProcess: async (caseId, processId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/refund-processes/${processId}/approve`, payload)
            return data
        },
        
        addBankTransfer: async (caseId, processId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/refund-processes/${processId}/bank-transfers`, payload)
            return data
        },
        
        getBankTransfers: async (caseId, processId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/refund-processes/${processId}/bank-transfers`)
            return data
        },
        
        processBankTransfer: async (caseId, processId, transferId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/refund-processes/${processId}/bank-transfers/${transferId}/process`, payload)
            return data
        },
        
        rejectBankTransfer: async (caseId, processId, transferId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/refund-processes/${processId}/bank-transfers/${transferId}/reject`, payload)
            return data
        },
        
        // ============== KIAN SUBMISSIONS ==============
        createKian: async (caseId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/kian-submissions`, payload)
            return data
        },
        
        getKian: async (caseId) => {
            const { data } = await api.get(`/tax-cases/${caseId}/kian-submissions`)
            return data
        },
        
        submitKian: async (caseId, submissionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/kian-submissions/${submissionId}/submit`, payload)
            return data
        },
        
        recordKianResponse: async (caseId, submissionId, payload) => {
            const { data } = await api.post(`/tax-cases/${caseId}/kian-submissions/${submissionId}/record-response`, payload)
            return data
        },
        
        closeKian: async (caseId, submissionId, payload = {}) => {
            const { data } = await api.post(`/tax-cases/${caseId}/kian-submissions/${submissionId}/close`, payload)
            return data
        },
        
        // ============== REFERENCE DATA ==============
        getEntities: async () => {
            const { data } = await api.get('/entities')
            return data
        },
        
        getFiscalYears: async () => {
            const { data } = await api.get('/fiscal-years')
            return data
        },
        
        getCurrencies: async () => {
            const { data } = await api.get('/currencies')
            return data
        },
        
        getCaseStatuses: async () => {
            const { data } = await api.get('/case-statuses')
            return data
        },
        
        getCurrentUser: async () => {
            const { data } = await api.get('/user')
            return data
        },
    }
}

export default api
