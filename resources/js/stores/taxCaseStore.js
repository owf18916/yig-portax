import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useTaxCaseApi } from '../composables/useTaxCaseApi'

export const useTaxCaseStore = defineStore('taxCase', () => {
    const api = useTaxCaseApi()
    
    // ============= STATE =============
    const currentCase = ref(null)
    const cases = ref([])
    const loading = ref(false)
    const error = ref(null)
    const success = ref(null)
    
    // Workflow stage data
    const sp2Data = ref(null)
    const sphpData = ref(null)
    const skpData = ref(null)
    const objectionSubmissionData = ref(null)
    const spuhData = ref(null)
    const objectionDecisionData = ref(null)
    const appealSubmissionData = ref(null)
    const appealExplanationData = ref(null)
    const appealDecisionData = ref(null)
    const supremeCourtSubmissionData = ref(null)
    const supremeCourtDecisionData = ref(null)
    const refundProcessData = ref(null)
    const kianData = ref(null)
    
    // Supporting data
    const entities = ref([])
    const fiscalYears = ref([])
    const currencies = ref([])
    const caseStatuses = ref([])
    const workflowHistory = ref([])
    const documents = ref([])
    const bankTransfers = ref([])
    
    // ============= COMPUTED =============
    const currentStage = computed(() => currentCase.value?.current_stage || 0)
    const caseStatus = computed(() => currentCase.value?.status || 'DRAFT')
    const isLoading = computed(() => loading.value)
    const hasError = computed(() => error.value !== null)
    const hasSuccess = computed(() => success.value !== null)
    
    // ============= ACTIONS =============
    
    /**
     * Clear notifications
     */
    const clearNotifications = () => {
        error.value = null
        success.value = null
    }
    
    /**
     * Load reference data (entities, fiscal years, etc.)
     */
    const loadReferenceData = async () => {
        try {
            loading.value = true
            const [entitiesData, fyData, currencyData, statusData] = await Promise.all([
                api.getEntities(),
                api.getFiscalYears(),
                api.getCurrencies(),
                api.getCaseStatuses(),
            ])
            
            entities.value = entitiesData
            fiscalYears.value = fyData
            currencies.value = currencyData
            caseStatuses.value = statusData
        } catch (err) {
            error.value = err.message
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Get all tax cases
     */
    const fetchCases = async (params = {}) => {
        try {
            loading.value = true
            const response = await api.getTaxCases(params)
            cases.value = response.data || []
            success.value = 'Cases loaded successfully'
            setTimeout(() => clearNotifications(), 3000)
        } catch (err) {
            error.value = err.response?.data?.message || err.message
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Get single tax case
     */
    const fetchCase = async (caseId) => {
        try {
            loading.value = true
            const response = await api.getTaxCase(caseId)
            currentCase.value = response.data || response
            
            // Load workflow history and documents
            await Promise.all([
                fetchWorkflowHistory(caseId),
                fetchDocuments(caseId),
            ])
        } catch (err) {
            error.value = err.response?.data?.message || err.message
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Create new tax case
     */
    const createCase = async (payload) => {
        try {
            loading.value = true
            const response = await api.createTaxCase(payload)
            currentCase.value = response.data || response
            success.value = 'Case created successfully'
            setTimeout(() => clearNotifications(), 3000)
            return currentCase.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Update tax case
     */
    const updateCase = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.updateTaxCase(caseId, payload)
            currentCase.value = response.data || response
            success.value = 'Case updated successfully'
            setTimeout(() => clearNotifications(), 3000)
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Get workflow history
     */
    const fetchWorkflowHistory = async (caseId) => {
        try {
            const response = await api.getWorkflowHistory(caseId)
            workflowHistory.value = response.data || response
        } catch (err) {
            console.error('Error fetching workflow history:', err)
        }
    }
    
    /**
     * Get documents
     */
    const fetchDocuments = async (caseId) => {
        try {
            const response = await api.getDocuments(caseId)
            documents.value = response.data || response
        } catch (err) {
            console.error('Error fetching documents:', err)
        }
    }
    
    // ============= STAGE ACTIONS =============
    
    /**
     * SP2 Record operations
     */
    const createSp2 = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createSp2(caseId, payload)
            sp2Data.value = response.data || response
            success.value = 'SP2 record created'
            setTimeout(() => clearNotifications(), 3000)
            return sp2Data.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const approveSp2 = async (caseId, recordId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.approveSp2(caseId, recordId, payload)
            sp2Data.value = response.data || response
            currentCase.value = { ...currentCase.value, ...response.data }
            success.value = 'SP2 record approved'
            setTimeout(() => clearNotifications(), 3000)
            return sp2Data.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * SPHP Record operations
     */
    const createSphp = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createSphp(caseId, payload)
            sphpData.value = response.data || response
            success.value = 'SPHP record created'
            setTimeout(() => clearNotifications(), 3000)
            return sphpData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const approveSphp = async (caseId, recordId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.approveSphp(caseId, recordId, payload)
            sphpData.value = response.data || response
            success.value = 'SPHP record approved'
            setTimeout(() => clearNotifications(), 3000)
            return sphpData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * SKP Record operations
     */
    const createSkp = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createSkp(caseId, payload)
            skpData.value = response.data || response
            success.value = 'SKP record created'
            setTimeout(() => clearNotifications(), 3000)
            return skpData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const approveSkp = async (caseId, recordId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.approveSkp(caseId, recordId, payload)
            skpData.value = response.data || response
            currentCase.value = { ...currentCase.value, ...response.data }
            success.value = 'SKP record approved. Routing based on SKP type...'
            setTimeout(() => clearNotifications(), 3000)
            return skpData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Objection operations
     */
    const createObjectionSubmission = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createObjectionSubmission(caseId, payload)
            objectionSubmissionData.value = response.data || response
            success.value = 'Objection submission created'
            setTimeout(() => clearNotifications(), 3000)
            return objectionSubmissionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const submitObjection = async (caseId, submissionId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.submitObjection(caseId, submissionId, payload)
            objectionSubmissionData.value = response.data || response
            success.value = 'Objection submitted. Awaiting response (SPUH)...'
            setTimeout(() => clearNotifications(), 3000)
            return objectionSubmissionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Objection Decision operations
     */
    const createObjectionDecision = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createObjectionDecision(caseId, payload)
            objectionDecisionData.value = response.data || response
            success.value = 'Objection decision created'
            setTimeout(() => clearNotifications(), 3000)
            return objectionDecisionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const approveObjectionDecision = async (caseId, decisionId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.approveObjectionDecision(caseId, decisionId, payload)
            objectionDecisionData.value = response.data || response
            success.value = 'Decision approved. Routing based on decision type...'
            setTimeout(() => clearNotifications(), 3000)
            return objectionDecisionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Appeal Decision operations
     */
    const createAppealDecision = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createAppealDecision(caseId, payload)
            appealDecisionData.value = response.data || response
            success.value = 'Appeal decision created'
            setTimeout(() => clearNotifications(), 3000)
            return appealDecisionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const approveAppealDecision = async (caseId, decisionId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.approveAppealDecision(caseId, decisionId, payload)
            appealDecisionData.value = response.data || response
            success.value = 'Appeal decision approved. Routing...'
            setTimeout(() => clearNotifications(), 3000)
            return appealDecisionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Supreme Court operations
     */
    const createSupremeCourtDecision = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createSupremeCourtDecision(caseId, payload)
            supremeCourtDecisionData.value = response.data || response
            success.value = 'Supreme Court decision created'
            setTimeout(() => clearNotifications(), 3000)
            return supremeCourtDecisionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const approveSupremeCourtDecision = async (caseId, decisionId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.approveSupremeCourtDecision(caseId, decisionId, payload)
            supremeCourtDecisionData.value = response.data || response
            success.value = 'Supreme Court decision approved. Case finalized.'
            setTimeout(() => clearNotifications(), 3000)
            return supremeCourtDecisionData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    /**
     * Refund operations
     */
    const createRefundProcess = async (caseId, payload) => {
        try {
            loading.value = true
            const response = await api.createRefundProcess(caseId, payload)
            refundProcessData.value = response.data || response
            success.value = 'Refund process created'
            setTimeout(() => clearNotifications(), 3000)
            return refundProcessData.value
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const addBankTransfer = async (caseId, processId, payload) => {
        try {
            loading.value = true
            const response = await api.addBankTransfer(caseId, processId, payload)
            bankTransfers.value.push(response.data || response)
            success.value = 'Bank transfer added'
            setTimeout(() => clearNotifications(), 3000)
            return response.data || response
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    const processBankTransfer = async (caseId, processId, transferId, payload = {}) => {
        try {
            loading.value = true
            const response = await api.processBankTransfer(caseId, processId, transferId, payload)
            const index = bankTransfers.value.findIndex(t => t.id === transferId)
            if (index !== -1) {
                bankTransfers.value[index] = response.data || response
            }
            success.value = 'Bank transfer processed'
            setTimeout(() => clearNotifications(), 3000)
            return response.data || response
        } catch (err) {
            error.value = err.response?.data?.message || err.message
            throw err
        } finally {
            loading.value = false
        }
    }
    
    return {
        // State
        currentCase,
        cases,
        loading,
        error,
        success,
        sp2Data,
        sphpData,
        skpData,
        objectionSubmissionData,
        spuhData,
        objectionDecisionData,
        appealSubmissionData,
        appealExplanationData,
        appealDecisionData,
        supremeCourtSubmissionData,
        supremeCourtDecisionData,
        refundProcessData,
        kianData,
        entities,
        fiscalYears,
        currencies,
        caseStatuses,
        workflowHistory,
        documents,
        bankTransfers,
        
        // Computed
        currentStage,
        caseStatus,
        isLoading,
        hasError,
        hasSuccess,
        
        // Actions
        clearNotifications,
        loadReferenceData,
        fetchCases,
        fetchCase,
        createCase,
        updateCase,
        fetchWorkflowHistory,
        fetchDocuments,
        createSp2,
        approveSp2,
        createSphp,
        approveSphp,
        createSkp,
        approveSkp,
        createObjectionSubmission,
        submitObjection,
        createObjectionDecision,
        approveObjectionDecision,
        createAppealDecision,
        approveAppealDecision,
        createSupremeCourtDecision,
        approveSupremeCourtDecision,
        createRefundProcess,
        addBankTransfer,
        processBankTransfer,
    }
})
