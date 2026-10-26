<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'

const props = defineProps({
    classes: { type: Array, default: () => [] },
    classInfo: { type: Object, default: null },
    feeTypes: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    availableYears: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
})

// ============================================================
// TOAST
// ============================================================
const toast = ref({ show: false, message: '', type: 'success' })
let toastTimeout = null
const showToast = (message, type = 'success') => {
    if (toastTimeout) clearTimeout(toastTimeout)
    toast.value = { show: true, message, type }
    toastTimeout = setTimeout(() => { toast.value.show = false }, 4000)
}

if (props.flash?.success) showToast(props.flash.success, 'success')
if (props.flash?.error) showToast(props.flash.error, 'error')

// ============================================================
// ✅ MONTH CALCULATION ENGINE
// ============================================================
const ACADEMIC_START_MONTH = 0 // 0 = January, 3 = April, 6 = July

const getMonthsElapsed = (academicYear) => {
    const now = new Date()
    const currentYear = now.getFullYear()
    const currentMonth = now.getMonth()
    const startYear = Number(academicYear) || currentYear

    if (currentYear === startYear) {
        return Math.max(1, currentMonth - ACADEMIC_START_MONTH + 1)
    }
    if (currentYear > startYear) {
        return 12
    }
    return 1
}

const getFeeMultiplier = (fee) => {
    const cycle = String(fee.cycle || '').toLowerCase()
    if (cycle === 'monthly') {
        return getMonthsElapsed(props.classInfo?.academic_year || new Date().getFullYear())
    }
    return 1
}

const getFeeEffectiveAmount = (fee) => {
    return Number(fee.amount || 0) * getFeeMultiplier(fee)
}

// ============================================================
// Year Filter State
// ============================================================
const selectedYear = ref(props.filters.academic_year || new Date().getFullYear())
const yearInput = ref(selectedYear.value)
const showYearDropdown = ref(false)

const yearSuggestions = computed(() => {
    const years = [...props.availableYears]
    const currentYear = new Date().getFullYear()
    if (!years.includes(currentYear)) years.push(currentYear)
    return years.sort((a, b) => b - a)
})

const filteredYearSuggestions = computed(() => {
    if (!yearInput.value) return yearSuggestions.value
    const input = String(yearInput.value).trim()
    return yearSuggestions.value.filter(y => String(y).includes(input))
})

const applyYear = () => {
    const year = String(yearInput.value).trim()
    if (!/^\d{4}$/.test(year)) { yearInput.value = selectedYear.value; showYearDropdown.value = false; return }
    const numYear = parseInt(year)
    if (numYear < 2000 || numYear > 2100) { yearInput.value = selectedYear.value; showYearDropdown.value = false; return }
    if (numYear === selectedYear.value) { showYearDropdown.value = false; return }
    selectedYear.value = numYear
    showYearDropdown.value = false
    handleYearChange()
}

const selectYearSuggestion = (year) => {
    yearInput.value = year
    selectedYear.value = year
    showYearDropdown.value = false
    handleYearChange()
}

const incrementYear = () => { yearInput.value = parseInt(yearInput.value || selectedYear.value) + 1; applyYear() }
const decrementYear = () => { yearInput.value = parseInt(yearInput.value || selectedYear.value) - 1; applyYear() }

watch(() => props.filters.academic_year, (newYear) => {
    if (newYear) { selectedYear.value = newYear; yearInput.value = newYear }
})

const handleYearChange = () => {
    router.get(route('fees.index'), { academic_year: selectedYear.value }, { preserveScroll: true, preserveState: true })
}

const openClass = (classId) => router.visit(route('fees.show', { classId }))
const backToClasses = () => router.visit(route('fees.index', { academic_year: selectedYear.value }))

// ============================================================
// Search
// ============================================================
const search = ref(props.filters.search || '')
let searchTimeout = null
watch(search, () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        if (!props.classInfo) return
        router.get(route('fees.show', props.classInfo.id), { search: search.value }, {
            preserveScroll: true, preserveState: true, replace: true,
        })
    }, 500)
})
const resetSearch = () => { search.value = '' }

// ============================================================
// ✅ EXPANDED STUDENTS
// ============================================================
const expandedStudents = ref(new Set())

const toggleStudentExpand = (studentId) => {
    const set = new Set(expandedStudents.value)
    if (set.has(studentId)) {
        set.delete(studentId)
    } else {
        set.add(studentId)
    }
    expandedStudents.value = set
}

const isStudentExpanded = (studentId) => expandedStudents.value.has(studentId)

// ============================================================
// POLICY MODAL
// ============================================================
const showPolicyModal = ref(false)
const selectedStudent = ref(null)
const activeFeeTab = ref(null)
const policyForm = ref({})
const submitting = ref(false)

const discountOptions = [
    { value: 'none',    label: 'No Discount',  icon: '❌' },
    { value: 'percent', label: 'Custom %',     icon: '📊' },
    { value: 'amount',  label: 'Fixed Amount', icon: '💰' },
    { value: 'free',    label: 'Free (100%)',  icon: '🎁' },
]
const quickPresets = [0, 10, 20, 25, 50, 75, 100]

// ✅ Manage open korle default-e monthly fee-er tab select thakbe
// (monthly fee na thakle prothom fee)
const getDefaultFeeTabId = () => {
    const monthly = props.feeTypes.find(f => String(f.cycle || '').toLowerCase() === 'monthly')
    return monthly?.id ?? props.feeTypes[0]?.id ?? null
}

const openPolicyEditor = (student) => {
    selectedStudent.value = student
    const form = {}
    props.feeTypes.forEach(fee => {
        const existing = (student.policies && student.policies[fee.id]) ? student.policies[fee.id] : null
        form[fee.id] = existing
            ? { discount_type: existing.discount_type || 'none', discount_value: Number(existing.discount_value) || 0, reason: existing.reason || '' }
            : { discount_type: 'none', discount_value: 0, reason: '' }
    })
    policyForm.value = form
    activeFeeTab.value = getDefaultFeeTabId()
    showPolicyModal.value = true
    document.body.style.overflow = 'hidden'
}

const closePolicyModal = () => {
    showPolicyModal.value = false
    selectedStudent.value = null
    policyForm.value = {}
    document.body.style.overflow = ''
}

const getPayable = (feeId) => {
    const fee = props.feeTypes.find(f => f.id === feeId)
    const policy = policyForm.value[feeId]
    if (!fee || !policy) return getFeeEffectiveAmount(fee) || 0
    const baseAmount = getFeeEffectiveAmount(fee)
    if (policy.discount_type === 'free') return 0
    if (policy.discount_type === 'percent') return Math.max(0, Math.round(baseAmount * (1 - (policy.discount_value / 100))))
    if (policy.discount_type === 'amount') return Math.max(0, baseAmount - policy.discount_value)
    return baseAmount
}

const getDiscountAmount = (feeId) => {
    const fee = props.feeTypes.find(f => f.id === feeId)
    if (!fee) return 0
    return getFeeEffectiveAmount(fee) - getPayable(feeId)
}

const applyPreset = (feeId, percent) => {
    if (percent === 0) policyForm.value[feeId] = { ...policyForm.value[feeId], discount_type: 'none', discount_value: 0 }
    else if (percent === 100) policyForm.value[feeId] = { ...policyForm.value[feeId], discount_type: 'free', discount_value: 100 }
    else policyForm.value[feeId] = { ...policyForm.value[feeId], discount_type: 'percent', discount_value: percent }
}

const selectedStudentSummary = computed(() => {
    if (!selectedStudent.value) return null
    let regular = 0, payable = 0
    props.feeTypes.forEach(fee => {
        const eff = getFeeEffectiveAmount(fee)
        regular += eff
        payable += getPayable(fee.id)
    })
    return {
        regular, payable,
        discount: regular - payable,
        discountPercent: regular > 0 ? Math.round(((regular - payable) / regular) * 100) : 0,
    }
})

const getRowTotals = (student) => {
    let regular = 0, payable = 0
    props.feeTypes.forEach(fee => {
        const effAmount = getFeeEffectiveAmount(fee)
        regular += effAmount
        const policy = student.policies && student.policies[fee.id] ? student.policies[fee.id] : null
        if (!policy) { payable += effAmount; return }
        if (policy.discount_type === 'free') return
        if (policy.discount_type === 'percent') payable += Math.max(0, Math.round(effAmount * (1 - (policy.discount_value / 100))))
        else if (policy.discount_type === 'amount') payable += Math.max(0, effAmount - policy.discount_value)
        else payable += effAmount
    })
    return { regular, payable, discount: regular - payable }
}

const getStudentFeeBreakdown = (student) => {
    const monthsElapsed = getMonthsElapsed(props.classInfo?.academic_year || new Date().getFullYear())
    return props.feeTypes.map(fee => {
        const policy = (student.policies && student.policies[fee.id]) ? student.policies[fee.id] : null
        const effAmount = getFeeEffectiveAmount(fee)
        const multiplier = getFeeMultiplier(fee)
        let discountAmount = 0
        let payable = effAmount
        let discountLabel = 'No Discount'
        let discountType = 'none'

        if (policy) {
            discountType = policy.discount_type || 'none'
            if (policy.discount_type === 'free') {
                discountAmount = effAmount
                payable = 0
                discountLabel = 'Free (100%)'
            } else if (policy.discount_type === 'percent') {
                discountAmount = Math.round(effAmount * (policy.discount_value / 100))
                payable = Math.max(0, effAmount - discountAmount)
                discountLabel = `${policy.discount_value}% Off`
            } else if (policy.discount_type === 'amount') {
                discountAmount = Math.min(effAmount, policy.discount_value)
                payable = Math.max(0, effAmount - discountAmount)
                discountLabel = `৳${policy.discount_value} Off`
            }
        }

        return {
            id: fee.id,
            name: fee.name,
            cycle: fee.cycle,
            amount: effAmount,
            baseAmount: Number(fee.amount || 0),
            multiplier,
            isMonthly: multiplier > 1,
            discountAmount,
            discountLabel,
            discountType,
            payable,
            reason: policy?.reason || '',
        }
    })
}

const getStudentPaid = (student) => Number(student.paid_amount || 0)
const getStudentDue = (student) => Math.max(0, getRowTotals(student).payable - getStudentPaid(student))

const getStudentStatusLabel = (student) => {
    const totals = getRowTotals(student)
    if (totals.regular === 0) return '—'
    if (totals.payable === 0) return 'Free'
    if (totals.discount > 0) {
        const pct = Math.round((totals.discount / totals.regular) * 100)
        return `${pct}% Off`
    }
    return 'Regular'
}

const getStudentStatusClass = (student) => {
    const totals = getRowTotals(student)
    if (totals.payable === 0) return 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700'
    if (totals.discount > 0) return 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border-blue-300 dark:border-blue-700'
    return 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-600'
}

// ============================================================
// PAYMENT MODAL
// ============================================================
const showPaymentModal = ref(false)
const payingStudent = ref(null)
const paymentForm = ref({ amount: 0, note: '' })
const savingPayment = ref(false)

const openPaymentModal = (student) => {
    payingStudent.value = student
    const due = getStudentDue(student)
    paymentForm.value = { amount: due, note: '' }
    showPaymentModal.value = true
    document.body.style.overflow = 'hidden'
}

const closePaymentModal = () => {
    showPaymentModal.value = false
    payingStudent.value = null
    paymentForm.value = { amount: 0, note: '' }
    document.body.style.overflow = ''
}

const payingTotals = computed(() => {
    if (!payingStudent.value) return { payable: 0, paid: 0, due: 0 }
    const totals = getRowTotals(payingStudent.value)
    const paid = getStudentPaid(payingStudent.value)
    return {
        payable: totals.payable,
        paid,
        due: Math.max(0, totals.payable - paid),
    }
})

const submitPayment = () => {
    if (!payingStudent.value) return
    const amt = Number(paymentForm.value.amount)
    if (!amt || amt <= 0) { showToast('Please enter a valid amount', 'error'); return }
    if (amt > payingTotals.value.due) { showToast('Amount exceeds due', 'error'); return }

    savingPayment.value = true
    router.post(route('fees.collect.payment'), {
        student_id: payingStudent.value.id,
        academic_year: props.classInfo?.academic_year || selectedYear.value,
        amount: amt,
        note: paymentForm.value.note || null,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            showToast(page.props.flash?.success || 'Payment recorded! ✅', 'success')
            closePaymentModal()
        },
        onError: (errors) => {
            const first = Object.values(errors)[0]
            showToast(first || 'Failed to save payment', 'error')
        },
        onFinish: () => { savingPayment.value = false },
    })
}

// ============================================================
// PAYMENT HISTORY MODAL
// ============================================================
const showHistoryModal = ref(false)
const historyStudent = ref(null)

const openHistoryModal = (student) => {
    historyStudent.value = student
    showHistoryModal.value = true
    document.body.style.overflow = 'hidden'
}

const closeHistoryModal = () => {
    showHistoryModal.value = false
    historyStudent.value = null
    document.body.style.overflow = ''
}

const formatDateTime = (dt) => {
    if (!dt) return '—'
    try {
        const d = new Date(dt)
        return d.toLocaleString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        })
    } catch (e) {
        return dt
    }
}

const savePolicy = () => {
    if (!selectedStudent.value) return
    const policies = Object.keys(policyForm.value).map(feeId => ({
        fee_structure_id: Number(feeId),
        discount_type: policyForm.value[feeId].discount_type,
        discount_value: Number(policyForm.value[feeId].discount_value) || 0,
        reason: policyForm.value[feeId].reason || '',
    }))
    submitting.value = true
    router.post(route('fees.policy.save'), {
        student_id: selectedStudent.value.id,
        academic_year: props.classInfo?.academic_year || selectedYear.value,
        policies,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            showToast(page.props.flash?.success || 'Fee policy updated! ✏️', 'success')
            closePolicyModal()
        },
        onError: (errors) => {
            const first = Object.values(errors)[0]
            showToast(first || 'Failed to save policy', 'error')
        },
        onFinish: () => { submitting.value = false },
    })
}

// ============================================================
// STATS
// ============================================================
const summaryStats = computed(() => {
    let totalRegular = 0, totalPayable = 0, totalPaid = 0, discountedCount = 0
    props.students.forEach(student => {
        const totals = getRowTotals(student)
        totalRegular += totals.regular
        totalPayable += totals.payable
        totalPaid += getStudentPaid(student)
        if (totals.discount > 0) discountedCount++
    })
    return {
        totalRegular, totalPayable, totalPaid,
        totalDue: Math.max(0, totalPayable - totalPaid),
        totalDiscount: totalRegular - totalPayable,
        discountedCount,
        freeCount: props.students.filter(s => getRowTotals(s).payable === 0).length,
    }
})

const monthsElapsed = computed(() =>
    getMonthsElapsed(props.classInfo?.academic_year || new Date().getFullYear())
)
</script>

<template>
    <Head :title="classInfo ? `Fees - ${classInfo.class_name}` : 'Fees Management'" />
    <AuthenticatedLayout>
        <div class="w-full px-3 py-4 sm:px-4 sm:py-6 md:px-6 lg:px-8 bg-gradient-to-br from-emerald-50/30 via-white to-teal-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 min-h-screen transition-colors duration-300">

            <!-- TOAST -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-[-20px]"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 translate-y-[-20px]"
                >
                    <div
                        v-if="toast.show"
                        :class="[
                            'fixed top-4 right-4 left-4 sm:left-auto z-[99999] max-w-xs sm:max-w-md w-full mx-auto sm:mx-0 p-4 rounded-xl shadow-2xl border-2 backdrop-blur-xl',
                            toast.type === 'success' ? 'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-500 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200' : '',
                            toast.type === 'error'   ? 'bg-rose-50 dark:bg-rose-900/40 border-rose-500 dark:border-rose-700 text-rose-800 dark:text-rose-200' : '',
                            toast.type === 'warning' ? 'bg-amber-50 dark:bg-amber-900/40 border-amber-500 dark:border-amber-700 text-amber-800 dark:text-amber-200' : '',
                        ]"
                    >
                        <div class="flex items-start gap-3">
                            <span class="text-xl flex-shrink-0">
                                {{ toast.type === 'success' ? '✅' : toast.type === 'error' ? '❌' : '⚠️' }}
                            </span>
                            <p class="text-sm font-semibold flex-1 break-words">{{ toast.message }}</p>
                            <button @click="toast.show = false" class="text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 flex-shrink-0">✕</button>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- VIEW 1: CLASS LIST -->
            <template v-if="!classInfo">
                <div class="mb-4 sm:mb-6">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl shadow-lg shadow-emerald-500/25 flex-shrink-0">
                            <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-emerald-800 to-teal-800 dark:from-emerald-300 dark:to-teal-300 bg-clip-text text-transparent truncate">Fees Management</h1>
                            <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 flex items-center gap-2 mt-1">
                                <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                Select a class to view students fee policies
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm p-4 sm:p-5 transition-colors duration-300">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex-shrink-0">
                                <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-emerald-800 dark:text-emerald-200">Academic Year</h3>
                                <p class="text-xs text-emerald-500 dark:text-emerald-400">Select or type a year to view classes</p>
                            </div>
                        </div>

                        <div class="relative flex items-center gap-2 w-full sm:w-auto">
                            <button type="button" @click="decrementYear" class="p-2 rounded-lg border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 hover:border-emerald-400 transition-colors active:scale-95 flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>

                            <div class="relative flex-1 sm:flex-none" @focusout="setTimeout(() => showYearDropdown = false, 200)">
                                <input v-model="yearInput" @focus="showYearDropdown = true" @keydown.enter.prevent="applyYear" @keydown.esc="showYearDropdown = false" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="YYYY" class="w-full sm:w-36 rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 pl-4 pr-10 py-2 sm:py-2.5 text-sm font-bold text-emerald-900 dark:text-emerald-100 text-center tracking-wider outline-none cursor-text focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10" />
                                <button type="button" @click="showYearDropdown = !showYearDropdown" class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-md text-emerald-500 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 transition-colors">
                                    <svg class="w-4 h-4 transition-transform duration-200" :class="showYearDropdown ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>

                                <div v-if="showYearDropdown && filteredYearSuggestions.length > 0" class="absolute top-full left-0 sm:left-auto sm:right-0 mt-2 w-full sm:w-44 bg-white dark:bg-slate-800 rounded-xl border-2 border-emerald-200 dark:border-emerald-800 shadow-xl z-50 overflow-hidden">
                                    <div class="max-h-60 overflow-y-auto py-1">
                                        <button v-for="year in filteredYearSuggestions" :key="year" type="button" @mousedown.prevent="selectYearSuggestion(year)" class="w-full text-left px-4 py-2 text-sm font-medium transition-colors flex items-center justify-between" :class="String(year) === String(selectedYear) ? 'bg-emerald-600 text-white' : 'text-emerald-800 dark:text-emerald-200 hover:bg-emerald-50 dark:hover:bg-emerald-950/50'">
                                            <span>{{ year }}</span>
                                            <span v-if="year === new Date().getFullYear()" class="text-[9px] px-1.5 py-0.5 rounded-full font-bold" :class="String(year) === String(selectedYear) ? 'bg-white/20 text-white' : 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'">NOW</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button type="button" @click="incrementYear" class="p-2 rounded-lg border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 hover:border-emerald-400 transition-colors active:scale-95 flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="classes.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm text-center transition-colors duration-300">
                    <h3 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200 mb-2">No Classes Found</h3>
                    <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400">No classes for year <strong>{{ selectedYear }}</strong>.</p>
                </div>

                <div v-else>
                    <div class="mb-4 flex items-center gap-2 flex-wrap">
                        <h2 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200">📚 Classes for {{ selectedYear }}</h2>
                        <span class="text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300 px-2.5 py-1 rounded-full font-semibold">{{ classes.length }} class{{ classes.length > 1 ? 'es' : '' }}</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                        <button v-for="cls in classes" :key="cls.id" @click="openClass(cls.id)" class="group relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-6 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm hover:shadow-xl hover:border-emerald-400 dark:hover:border-emerald-600 transition-all duration-300 text-left overflow-hidden">
                            <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br from-emerald-400/20 to-teal-500/20 dark:from-emerald-600/20 dark:to-teal-700/20 group-hover:scale-150 transition-transform duration-500"></div>
                            <div class="relative">
                                <div class="flex items-start justify-between mb-3 sm:mb-4">
                                    <div class="p-2.5 sm:p-3 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/20">
                                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                        </svg>
                                    </div>
                                    <span class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">{{ cls.subjects?.length || 0 }} subjects</span>
                                </div>
                                <h3 class="text-lg sm:text-xl font-bold text-emerald-900 dark:text-emerald-100 mb-1 truncate">{{ cls.class_name }}</h3>
                                <p class="text-sm font-semibold text-teal-600 dark:text-teal-400 mb-1">Section: {{ cls.section || 'N/A' }}</p>
                                <p class="text-xs text-emerald-500 dark:text-emerald-400 mb-3 flex items-center gap-1 font-medium">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    <span class="truncate">Teacher: {{ cls.class_teacher || 'Unassigned' }}</span>
                                </p>
                                <div class="mb-3">
                                    <span :class="['inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border', cls.fee_count > 0 ? 'bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : 'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800']">
                                        💰 {{ cls.fee_count }} fee type{{ cls.fee_count !== 1 ? 's' : '' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-3 sm:pt-4 border-t border-emerald-100 dark:border-emerald-800">
                                    <span class="text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                        {{ cls.student_count }} students
                                    </span>
                                    <span class="text-xs font-semibold text-teal-600 dark:text-teal-400 group-hover:text-teal-800 dark:group-hover:text-teal-200 transition-colors">Manage Fees →</span>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </template>

            <!-- VIEW 2: STUDENT LIST -->
            <template v-else>
                <div class="mb-4 sm:mb-6">
                    <button @click="backToClasses" class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-200 mb-3 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Classes
                    </button>

                    <div class="flex items-center gap-3 sm:gap-4">
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl shadow-lg shadow-emerald-500/25 flex-shrink-0">
                            <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-lg sm:text-2xl font-bold bg-gradient-to-r from-emerald-800 to-teal-800 dark:from-emerald-300 dark:to-teal-300 bg-clip-text text-transparent truncate">
                                {{ classInfo.class_name }} — {{ classInfo.section || 'N/A' }}
                            </h1>
                            <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 mt-0.5">
                                Academic Year: {{ classInfo.academic_year }}
                                <span class="mx-1">·</span><span class="font-semibold">{{ students.length }}</span> students
                                <span class="mx-1">·</span><span class="font-semibold">{{ feeTypes.length }}</span> fee types
                                <span class="mx-1">·</span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-[10px] font-bold border border-amber-300 dark:border-amber-700">
                                    ⏱ {{ monthsElapsed }} month{{ monthsElapsed > 1 ? 's' : '' }} elapsed
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="feeTypes.length === 0" class="mb-4 p-4 rounded-xl bg-amber-50 dark:bg-amber-950/40 border-2 border-amber-300 dark:border-amber-800">
                    <p class="text-sm font-bold text-amber-800 dark:text-amber-200">⚠️ No fee structure set for this class</p>
                    <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">Please set up the fee structure first.</p>
                </div>

                <div v-else class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm p-4 sm:p-5 transition-colors duration-300">
                    <div class="flex items-center gap-3">
                        <div class="relative flex-1">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-emerald-400 dark:text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input v-model="search" type="text" placeholder="Search student by name, ID or roll..." class="w-full rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 pl-10 pr-4 py-2 sm:py-2.5 text-sm text-emerald-900 dark:text-emerald-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition-all focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10" />
                        </div>
                        <button v-if="search" @click="resetSearch" class="p-2.5 rounded-xl border-2 border-rose-200 dark:border-rose-800 bg-white dark:bg-slate-800 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/50 transition-colors active:scale-95">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Summary Stats -->
                <div v-if="feeTypes.length > 0" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4 mb-4 sm:mb-6">
                    <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-emerald-200/50 dark:border-emerald-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-emerald-400 dark:text-emerald-500 uppercase tracking-wider">Students</p>
                        <p class="text-xl sm:text-2xl font-bold text-emerald-800 dark:text-emerald-200">{{ students.length }}</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-green-200/50 dark:border-green-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-green-500 dark:text-green-400 uppercase tracking-wider">Free</p>
                        <p class="text-xl sm:text-2xl font-bold text-green-600 dark:text-green-400">{{ summaryStats.freeCount }}</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-blue-200/50 dark:border-blue-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-blue-500 dark:text-blue-400 uppercase tracking-wider">Discounted</p>
                        <p class="text-xl sm:text-2xl font-bold text-blue-600 dark:text-blue-400">{{ summaryStats.discountedCount }}</p>
                    </div>
                    <div class="bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-950/40 dark:to-teal-950/40 rounded-xl p-3 sm:p-4 border border-emerald-200/50 dark:border-emerald-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-emerald-500 dark:text-emerald-400 uppercase tracking-wider">Payable</p>
                        <p class="text-xs sm:text-base font-bold text-emerald-700 dark:text-emerald-300 mt-1">৳ {{ summaryStats.totalPayable.toLocaleString() }}</p>
                    </div>
                    <div class="bg-gradient-to-br from-cyan-50 to-blue-50 dark:from-cyan-950/40 dark:to-blue-950/40 rounded-xl p-3 sm:p-4 border border-cyan-200/50 dark:border-cyan-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-cyan-600 dark:text-cyan-400 uppercase tracking-wider">Collected</p>
                        <p class="text-xs sm:text-base font-bold text-cyan-700 dark:text-cyan-300 mt-1">৳ {{ summaryStats.totalPaid.toLocaleString() }}</p>
                    </div>
                    <div class="bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-950/40 dark:to-orange-950/40 rounded-xl p-3 sm:p-4 border border-amber-200/50 dark:border-amber-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">Due</p>
                        <p class="text-xs sm:text-base font-bold text-amber-700 dark:text-amber-300 mt-1">৳ {{ summaryStats.totalDue.toLocaleString() }}</p>
                    </div>
                </div>

                <div v-if="feeTypes.length > 0 && students.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm text-center transition-colors duration-300">
                    <h3 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200 mb-2">No Students Found</h3>
                    <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400">{{ search ? `No students match "${search}".` : 'This class has no active students.' }}</p>
                </div>

                <!-- Student List -->
                <div v-else-if="feeTypes.length > 0" class="space-y-3 sm:space-y-4">
                    <div v-for="student in students" :key="student.id" class="bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm overflow-hidden hover:shadow-md transition-shadow">

                        <!-- Student header row -->
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3 sm:p-4">
                            <div class="flex items-center gap-3 min-w-0 w-full sm:w-auto">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center overflow-hidden flex-shrink-0 ring-2 ring-white dark:ring-slate-800 shadow-sm">
                                    <img v-if="student.photo" :src="'/storage/' + student.photo" :alt="student.name" class="w-full h-full object-cover" />
                                    <span v-else class="text-xs sm:text-sm font-bold text-white">{{ student.name?.charAt(0)?.toUpperCase() }}</span>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-sm sm:text-base font-bold text-emerald-900 dark:text-emerald-100 truncate">{{ student.name }}</h3>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 truncate">
                                        {{ student.class }} - {{ student.section }}
                                        <span v-if="student.roll_number"> · Roll {{ student.roll_number }}</span>
                                        <span class="text-emerald-400 dark:text-emerald-500"> · {{ student.student_id }}</span>
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-1.5 sm:gap-2 flex-wrap w-full sm:w-auto justify-end">
                                <!-- Regular -->
                                <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-800 dark:to-slate-700 border-2 border-slate-300 dark:border-slate-600 text-center">
                                    <p class="text-[8px] sm:text-[9px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Regular</p>
                                    <p class="text-[10px] sm:text-xs font-bold text-slate-800 dark:text-slate-100">৳{{ getRowTotals(student).regular.toLocaleString() }}</p>
                                </div>

                                <!-- Discount -->
                                <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-rose-100 to-red-100 dark:from-rose-950/40 dark:to-red-950/40 border-2 border-rose-300 dark:border-rose-800 text-center">
                                    <p class="text-[8px] sm:text-[9px] font-bold text-rose-700 dark:text-rose-400 uppercase tracking-wider">Discount</p>
                                    <p class="text-[10px] sm:text-xs font-bold text-rose-800 dark:text-rose-300">−৳{{ getRowTotals(student).discount.toLocaleString() }}</p>
                                </div>

                                <!-- Payable -->
                                <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-emerald-100 to-teal-100 dark:from-emerald-950/40 dark:to-teal-950/40 border-2 border-emerald-300 dark:border-emerald-800 text-center">
                                    <p class="text-[8px] sm:text-[9px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Payable</p>
                                    <p class="text-[10px] sm:text-xs font-bold text-emerald-900 dark:text-emerald-200">৳{{ getRowTotals(student).payable.toLocaleString() }}</p>
                                </div>

                                <!-- Paid -->
                                <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-cyan-100 to-blue-100 dark:from-cyan-950/40 dark:to-blue-950/40 border-2 border-cyan-300 dark:border-cyan-800 text-center">
                                    <p class="text-[8px] sm:text-[9px] font-bold text-cyan-700 dark:text-cyan-400 uppercase tracking-wider">Paid</p>
                                    <p class="text-[10px] sm:text-xs font-bold text-cyan-900 dark:text-cyan-200">৳{{ getStudentPaid(student).toLocaleString() }}</p>
                                </div>

                                <!-- Due -->
                                <div
                                    class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg border-2 text-center"
                                    :class="getStudentDue(student) > 0 ? 'bg-gradient-to-br from-amber-100 to-orange-100 dark:from-amber-950/40 dark:to-orange-950/40 border-amber-300 dark:border-amber-800' : 'bg-gradient-to-br from-emerald-100 to-green-100 dark:from-emerald-950/40 dark:to-green-950/40 border-emerald-300 dark:border-emerald-800'"
                                >
                                    <p class="text-[8px] sm:text-[9px] font-bold uppercase tracking-wider" :class="getStudentDue(student) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'">Due</p>
                                    <p class="text-[10px] sm:text-xs font-bold" :class="getStudentDue(student) > 0 ? 'text-amber-900 dark:text-amber-200' : 'text-emerald-900 dark:text-emerald-200'">৳{{ getStudentDue(student).toLocaleString() }}</p>
                                </div>

                                <!-- Status -->
                                <span :class="['inline-flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-bold border', getStudentStatusClass(student)]">
                                    {{ getStudentStatusLabel(student) }}
                                </span>

                                <!-- Expand toggle -->
                                <button
                                    @click="toggleStudentExpand(student.id)"
                                    :class="[
                                        'inline-flex items-center gap-1 sm:gap-1.5 rounded-lg px-2 sm:px-3.5 py-1.5 sm:py-2 text-[10px] sm:text-xs font-bold shadow-sm transition-all active:scale-95',
                                        isStudentExpanded(student.id)
                                            ? 'bg-gradient-to-r from-slate-600 to-slate-800 hover:from-slate-700 hover:to-slate-900 text-white'
                                            : 'bg-gradient-to-r from-teal-500 to-cyan-600 hover:from-teal-600 hover:to-cyan-700 text-white'
                                    ]"
                                    :title="isStudentExpanded(student.id) ? 'Hide fee breakdown' : 'Show fee breakdown'"
                                >
                                    <svg
                                        class="w-3 h-3 sm:w-3.5 sm:h-3.5 transition-transform duration-200"
                                        :class="isStudentExpanded(student.id) ? 'rotate-180' : ''"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                                    </svg>
                                    Fees
                                </button>

                                <!-- Manage -->
                                <button @click="openPolicyEditor(student)" class="inline-flex items-center gap-1 sm:gap-1.5 rounded-lg bg-gradient-to-r from-indigo-500 to-purple-500 hover:from-indigo-600 hover:to-purple-600 px-2 sm:px-3.5 py-1.5 sm:py-2 text-[10px] sm:text-xs font-bold text-white shadow-sm transition-all active:scale-95">
                                    <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                                    </svg>
                                    Manage
                                </button>

                                <!-- History -->
                                <button
                                    @click="openHistoryModal(student)"
                                    :disabled="getStudentPaid(student) <= 0"
                                    :class="[
                                        'inline-flex items-center gap-1 sm:gap-1.5 rounded-lg px-2 sm:px-3.5 py-1.5 sm:py-2 text-[10px] sm:text-xs font-bold shadow-sm transition-all active:scale-95',
                                        getStudentPaid(student) <= 0
                                            ? 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-700 cursor-not-allowed'
                                            : 'bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700 text-white'
                                    ]"
                                    :title="getStudentPaid(student) <= 0 ? 'No payments yet' : 'View payment history'"
                                >
                                    <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    History
                                </button>

                                <!-- Collect -->
                                <button
                                    @click="openPaymentModal(student)"
                                    :disabled="getStudentDue(student) <= 0"
                                    :class="[
                                        'inline-flex items-center gap-1 sm:gap-1.5 rounded-lg px-2 sm:px-3.5 py-1.5 sm:py-2 text-[10px] sm:text-xs font-bold shadow-sm transition-all active:scale-95',
                                        getStudentDue(student) <= 0
                                            ? 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-700 cursor-not-allowed'
                                            : 'bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white'
                                    ]"
                                    :title="getStudentDue(student) <= 0 ? 'Already fully paid' : 'Collect payment'"
                                >
                                    <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                    Collect
                                </button>
                            </div>
                        </div>

                        <!-- EXPANDED FEE BREAKDOWN -->
                        <Transition
                            enter-active-class="transition-all ease-out duration-300"
                            enter-from-class="max-h-0 opacity-0"
                            enter-to-class="max-h-[800px] opacity-100"
                            leave-active-class="transition-all ease-in duration-200"
                            leave-from-class="max-h-[800px] opacity-100"
                            leave-to-class="max-h-0 opacity-0"
                        >
                            <div v-if="isStudentExpanded(student.id)" class="overflow-hidden border-t-2 border-dashed border-emerald-200 dark:border-emerald-800 bg-gradient-to-br from-emerald-50/40 to-teal-50/40 dark:from-emerald-950/20 dark:to-teal-950/20">
                                <div class="p-3 sm:p-4">
                                    <!-- Header -->
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="p-1.5 rounded-lg bg-emerald-500 text-white">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                        </div>
                                        <h4 class="text-xs sm:text-sm font-bold text-emerald-900 dark:text-emerald-200">
                                            Fee Breakdown — {{ getStudentFeeBreakdown(student).length }} item(s)
                                        </h4>
                                    </div>

                                    <!-- Desktop Table -->
                                    <div class="hidden sm:block overflow-x-auto rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-slate-900">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="bg-emerald-100/60 dark:bg-emerald-950/40">
                                                    <th class="px-3 py-2 text-left text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Fee Name</th>
                                                    <th class="px-3 py-2 text-center text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Cycle</th>
                                                    <th class="px-3 py-2 text-center text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Qty</th>
                                                    <th class="px-3 py-2 text-right text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Regular</th>
                                                    <th class="px-3 py-2 text-center text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Discount</th>
                                                    <th class="px-3 py-2 text-right text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Payable</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-emerald-100 dark:divide-emerald-800">
                                                <tr v-for="fee in getStudentFeeBreakdown(student)" :key="fee.id" class="hover:bg-emerald-50/40 dark:hover:bg-emerald-950/20 transition">
                                                    <td class="px-3 py-2.5">
                                                        <p class="text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100">{{ fee.name }}</p>
                                                        <p v-if="fee.reason" class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 italic">💬 {{ fee.reason }}</p>
                                                    </td>
                                                    <td class="px-3 py-2.5 text-center">
                                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                                                            {{ fee.cycle }}
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-2.5 text-center">
                                                        <span
                                                            v-if="fee.multiplier > 1"
                                                            class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-700"
                                                            :title="`${fee.baseAmount} × ${fee.multiplier} months`"
                                                        >
                                                            {{ fee.multiplier }}×
                                                        </span>
                                                        <span v-else class="text-[10px] text-slate-400 dark:text-slate-500 italic">1×</span>
                                                    </td>
                                                    <td class="px-3 py-2.5 text-right text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300">
                                                        ৳{{ fee.amount.toLocaleString() }}
                                                    </td>
                                                    <td class="px-3 py-2.5 text-center">
                                                        <span
                                                            v-if="fee.discountAmount > 0"
                                                            class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800"
                                                        >
                                                            −৳{{ fee.discountAmount.toLocaleString() }} ({{ fee.discountLabel }})
                                                        </span>
                                                        <span v-else class="text-[10px] text-slate-400 dark:text-slate-500 italic">None</span>
                                                    </td>
                                                    <td class="px-3 py-2.5 text-right">
                                                        <span
                                                            :class="[
                                                                'text-xs sm:text-sm font-bold',
                                                                fee.payable === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-emerald-800 dark:text-emerald-300'
                                                            ]"
                                                        >
                                                            ৳{{ fee.payable.toLocaleString() }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr class="bg-emerald-100/60 dark:bg-emerald-950/40 border-t-2 border-emerald-300 dark:border-emerald-800">
                                                    <td colspan="3" class="px-3 py-2 text-right text-[11px] font-bold text-emerald-900 dark:text-emerald-300 uppercase tracking-wider">Total</td>
                                                    <td class="px-3 py-2 text-right text-sm font-bold text-slate-800 dark:text-slate-100">
                                                        ৳{{ getRowTotals(student).regular.toLocaleString() }}
                                                    </td>
                                                    <td class="px-3 py-2 text-center text-sm font-bold text-rose-700 dark:text-rose-400">
                                                        −৳{{ getRowTotals(student).discount.toLocaleString() }}
                                                    </td>
                                                    <td class="px-3 py-2 text-right text-sm font-bold text-emerald-800 dark:text-emerald-300">
                                                        ৳{{ getRowTotals(student).payable.toLocaleString() }}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    <!-- Mobile Card List -->
                                    <div class="sm:hidden space-y-2">
                                        <div
                                            v-for="fee in getStudentFeeBreakdown(student)"
                                            :key="fee.id"
                                            class="p-3 rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-slate-900"
                                        >
                                            <div class="flex items-start justify-between gap-2 mb-2">
                                                <div class="min-w-0">
                                                    <p class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ fee.name }}</p>
                                                    <p class="text-[10px] text-slate-500 dark:text-slate-400">
                                                        {{ fee.cycle }}
                                                        <span v-if="fee.multiplier > 1" class="ml-1 font-bold text-amber-600 dark:text-amber-400">({{ fee.multiplier }}×)</span>
                                                    </p>
                                                </div>
                                                <span
                                                    v-if="fee.discountAmount > 0"
                                                    class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 flex-shrink-0"
                                                >
                                                    {{ fee.discountLabel }}
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-3 gap-2 text-center">
                                                <div>
                                                    <p class="text-[8px] font-bold text-slate-500 dark:text-slate-400 uppercase">Regular</p>
                                                    <p class="text-xs font-bold text-slate-700 dark:text-slate-300">৳{{ fee.amount.toLocaleString() }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-[8px] font-bold text-rose-600 dark:text-rose-400 uppercase">Discount</p>
                                                    <p class="text-xs font-bold text-rose-700 dark:text-rose-300">−৳{{ fee.discountAmount.toLocaleString() }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-[8px] font-bold text-emerald-600 dark:text-emerald-400 uppercase">Payable</p>
                                                    <p class="text-xs font-bold text-emerald-800 dark:text-emerald-300">৳{{ fee.payable.toLocaleString() }}</p>
                                                </div>
                                            </div>
                                            <p v-if="fee.reason" class="mt-2 text-[10px] text-slate-500 dark:text-slate-400 italic">💬 {{ fee.reason }}</p>
                                        </div>

                                        <!-- Mobile Total -->
                                        <div class="p-3 rounded-xl bg-emerald-100 dark:bg-emerald-950/40 border-2 border-emerald-300 dark:border-emerald-800">
                                            <div class="grid grid-cols-3 gap-2 text-center">
                                                <div>
                                                    <p class="text-[9px] font-bold text-emerald-900 dark:text-emerald-300 uppercase">Regular</p>
                                                    <p class="text-sm font-bold text-slate-800 dark:text-slate-100">৳{{ getRowTotals(student).regular.toLocaleString() }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-[9px] font-bold text-rose-700 dark:text-rose-400 uppercase">Discount</p>
                                                    <p class="text-sm font-bold text-rose-800 dark:text-rose-300">−৳{{ getRowTotals(student).discount.toLocaleString() }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-[9px] font-bold text-emerald-800 dark:text-emerald-300 uppercase">Payable</p>
                                                    <p class="text-sm font-bold text-emerald-900 dark:text-emerald-200">৳{{ getRowTotals(student).payable.toLocaleString() }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </template>
        </div>

        <!-- POLICY MODAL -->
        <Teleport to="body">
            <div
                v-if="showPolicyModal"
                class="fixed inset-0 z-[999] flex items-center justify-center p-2 sm:p-4 bg-slate-950/70 backdrop-blur-md overflow-y-auto"
                @click.self="closePolicyModal"
            >
                <div class="relative w-full max-w-4xl my-4 sm:my-8 bg-white dark:bg-slate-800 rounded-2xl shadow-2xl overflow-hidden transition-colors duration-300">
                    <div class="relative bg-gradient-to-r from-emerald-600 via-teal-600 to-teal-700 px-4 sm:px-6 py-4 sm:py-5">
                        <div class="relative flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center text-white font-bold text-base sm:text-lg border-2 border-white/30 shadow-lg flex-shrink-0 overflow-hidden">
                                    <img v-if="selectedStudent?.photo" :src="'/storage/' + selectedStudent.photo" class="w-full h-full object-cover" />
                                    <span v-else>{{ selectedStudent?.name?.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-base sm:text-lg font-bold text-white truncate">{{ selectedStudent?.name }}</h3>
                                    <p class="text-[10px] sm:text-xs text-emerald-100">{{ selectedStudent?.class }} - {{ selectedStudent?.section }} · {{ selectedStudent?.student_id }}</p>
                                </div>
                            </div>
                            <button @click="closePolicyModal" class="p-1.5 sm:p-2 rounded-lg text-white/80 hover:bg-white/20 transition-colors flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="bg-emerald-50/50 dark:bg-emerald-950/30 border-b border-emerald-100 dark:border-emerald-800 px-4 sm:px-6 py-3">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <p class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Regular</p>
                                <p class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 mt-0.5">৳ {{ selectedStudentSummary?.regular.toLocaleString() }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-rose-600 dark:text-rose-400 uppercase tracking-wider">Discount</p>
                                <p class="text-sm sm:text-base font-bold text-rose-600 dark:text-rose-400 mt-0.5">− ৳ {{ selectedStudentSummary?.discount.toLocaleString() }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Payable</p>
                                <p class="text-sm sm:text-base font-bold text-emerald-700 dark:text-emerald-300 mt-0.5">৳ {{ selectedStudentSummary?.payable.toLocaleString() }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-blue-700 dark:text-blue-400 uppercase tracking-wider">Discount %</p>
                                <p class="text-sm sm:text-base font-bold text-blue-700 dark:text-blue-300 mt-0.5">{{ selectedStudentSummary?.discountPercent }}%</p>
                            </div>
                        </div>
                    </div>

                    <div class="border-b border-emerald-100 dark:border-emerald-800 overflow-x-auto">
                        <div class="flex min-w-max px-2 sm:px-4">
                            <button v-for="fee in feeTypes" :key="fee.id" @click="activeFeeTab = fee.id" :class="['flex items-center gap-2 px-3 sm:px-4 py-3 text-xs sm:text-sm font-medium transition-all border-b-2 whitespace-nowrap', activeFeeTab === fee.id ? 'text-emerald-700 dark:text-emerald-300 border-emerald-500 bg-emerald-50/50 dark:bg-emerald-950/30' : 'text-slate-500 dark:text-slate-400 border-transparent hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800']">
                                <span>{{ fee.name }}</span>
                                <span class="text-[10px] bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-1.5 py-0.5 rounded-full font-bold">
                                    ৳{{ getFeeEffectiveAmount(fee).toLocaleString() }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="p-4 sm:p-6 max-h-[55vh] overflow-y-auto">
                        <div v-for="fee in feeTypes" :key="fee.id">
                            <div v-show="activeFeeTab === fee.id" class="space-y-4">
                                <div class="flex items-center gap-3 p-4 rounded-xl bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-950/40 dark:to-teal-950/40 border border-emerald-200 dark:border-emerald-800">
                                    <div class="p-3 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-md flex-shrink-0">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ fee.name }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ fee.cycle }} · Base: ৳{{ Number(fee.amount).toLocaleString() }}
                                            <span v-if="getFeeMultiplier(fee) > 1" class="ml-1 font-bold text-amber-600 dark:text-amber-400">
                                                × {{ getFeeMultiplier(fee) }} months = ৳{{ getFeeEffectiveAmount(fee).toLocaleString() }}
                                            </span>
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-2">Discount Type</label>
                                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
                                        <button v-for="opt in discountOptions" :key="opt.value" @click="policyForm[fee.id] = { ...policyForm[fee.id], discount_type: opt.value, discount_value: opt.value === 'free' ? 100 : (opt.value === 'none' ? 0 : (policyForm[fee.id]?.discount_value || 0)) }" :class="['flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 transition-all active:scale-95', policyForm[fee.id]?.discount_type === opt.value ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40 shadow-md' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-emerald-300 dark:hover:border-emerald-700']">
                                            <span class="text-xl">{{ opt.icon }}</span>
                                            <span class="text-[10px] sm:text-xs font-bold text-slate-700 dark:text-slate-300 text-center leading-tight">{{ opt.label }}</span>
                                        </button>
                                    </div>
                                </div>

                                <div v-if="policyForm[fee.id]?.discount_type === 'percent' || policyForm[fee.id]?.discount_type === 'amount'">
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-2">
                                        {{ policyForm[fee.id]?.discount_type === 'percent' ? 'Discount Percentage (%)' : 'Discount Amount (৳)' }}
                                    </label>
                                    <div class="relative">
                                        <input v-model.number="policyForm[fee.id].discount_value" type="number" min="0" :max="policyForm[fee.id]?.discount_type === 'percent' ? 100 : getFeeEffectiveAmount(fee)" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 text-base font-bold text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" />
                                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm font-bold text-slate-400 dark:text-slate-500">{{ policyForm[fee.id]?.discount_type === 'percent' ? '%' : '৳' }}</span>
                                    </div>
                                    <div v-if="policyForm[fee.id]?.discount_type === 'percent'" class="mt-3">
                                        <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Quick Select</p>
                                        <div class="flex flex-wrap gap-2">
                                            <button v-for="preset in quickPresets" :key="preset" @click="applyPreset(fee.id, preset)" :class="['px-3 py-1.5 rounded-lg text-xs font-bold transition-all active:scale-95', policyForm[fee.id]?.discount_value === preset ? 'bg-emerald-500 text-white shadow-md' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/40']">{{ preset }}%</button>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-2">Reason / Note (Optional)</label>
                                    <input v-model="policyForm[fee.id].reason" type="text" placeholder="e.g. Staff child, Full scholarship..." class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2.5 text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" />
                                </div>

                                <div class="p-4 rounded-xl bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-950/40 dark:to-teal-950/40 border-2 border-emerald-200 dark:border-emerald-800">
                                    <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-300 uppercase tracking-wider mb-2">Preview</p>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div>
                                            <p class="text-[9px] text-slate-500 dark:text-slate-400 font-semibold">Regular</p>
                                            <p class="text-sm font-bold text-slate-700 dark:text-slate-300">৳{{ getFeeEffectiveAmount(fee).toLocaleString() }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[9px] text-rose-600 dark:text-rose-400 font-semibold">Discount</p>
                                            <p class="text-sm font-bold text-rose-600 dark:text-rose-400">− ৳{{ getDiscountAmount(fee.id).toLocaleString() }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[9px] text-emerald-700 dark:text-emerald-400 font-semibold">Payable</p>
                                            <p class="text-base font-bold text-emerald-700 dark:text-emerald-300">৳{{ getPayable(fee.id).toLocaleString() }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 sm:px-6 py-4 bg-emerald-50/30 dark:bg-emerald-950/20 border-t border-emerald-100 dark:border-emerald-800">
                        <div class="text-[10px] sm:text-xs text-emerald-700 dark:text-emerald-400 text-center sm:text-left">💡 Changes apply from next billing cycle</div>
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <button @click="closePolicyModal" class="flex-1 sm:flex-none px-4 sm:px-5 py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all active:scale-95">Cancel</button>
                            <button @click="savePolicy" :disabled="submitting" class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-sm font-semibold hover:from-emerald-600 hover:to-teal-700 hover:shadow-lg transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg v-if="!submitting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                </svg>
                                <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                {{ submitting ? 'Saving...' : 'Save Policy' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- COLLECT PAYMENT MODAL -->
        <Teleport to="body">
            <div
                v-if="showPaymentModal"
                class="fixed inset-0 z-[1000] flex items-center justify-center p-2 sm:p-4 bg-slate-950/70 backdrop-blur-md overflow-y-auto"
                @click.self="closePaymentModal"
            >
                <div class="relative w-full max-w-lg my-4 sm:my-8 bg-white dark:bg-slate-800 rounded-2xl shadow-2xl overflow-hidden transition-colors duration-300">
                    <div class="relative bg-gradient-to-r from-emerald-600 via-green-600 to-teal-600 px-4 sm:px-6 py-4 sm:py-5">
                        <div class="relative flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center text-white font-bold text-base sm:text-lg border-2 border-white/30 shadow-lg flex-shrink-0 overflow-hidden">
                                    <img v-if="payingStudent?.photo" :src="'/storage/' + payingStudent.photo" class="w-full h-full object-cover" />
                                    <span v-else>{{ payingStudent?.name?.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-base sm:text-lg font-bold text-white truncate">Collect Payment</h3>
                                    <p class="text-[10px] sm:text-xs text-emerald-100">{{ payingStudent?.name }} · {{ payingStudent?.student_id }}</p>
                                </div>
                            </div>
                            <button @click="closePaymentModal" class="p-1.5 sm:p-2 rounded-lg text-white/80 hover:bg-white/20 transition-colors flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="bg-emerald-50/50 dark:bg-emerald-950/30 border-b border-emerald-100 dark:border-emerald-800 px-4 sm:px-6 py-4">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Payable</p>
                                <p class="text-base font-bold text-slate-800 dark:text-slate-100 mt-0.5">৳ {{ payingTotals.payable.toLocaleString() }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider">Paid</p>
                                <p class="text-base font-bold text-cyan-700 dark:text-cyan-300 mt-0.5">৳ {{ payingTotals.paid.toLocaleString() }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Due</p>
                                <p class="text-base font-bold text-amber-700 dark:text-amber-300 mt-0.5">৳ {{ payingTotals.due.toLocaleString() }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 sm:p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-2">
                                Amount (৳) <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <input v-model.number="paymentForm.amount" type="number" min="0" :max="payingTotals.due" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 text-lg font-bold text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" />
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm font-bold text-slate-400 dark:text-slate-500">৳</span>
                            </div>
                            <button type="button" @click="paymentForm.amount = payingTotals.due" class="mt-2 px-3 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs font-bold hover:bg-emerald-200 dark:hover:bg-emerald-900/60 transition">
                                Full Due (৳{{ payingTotals.due.toLocaleString() }})
                            </button>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-2">Note (Optional)</label>
                            <input v-model="paymentForm.note" type="text" placeholder="e.g. Partial payment, cash received..." class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2.5 text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" />
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center justify-end gap-2 px-4 sm:px-6 py-4 bg-emerald-50/30 dark:bg-emerald-950/20 border-t border-emerald-100 dark:border-emerald-800">
                        <button @click="closePaymentModal" class="w-full sm:w-auto px-5 py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all active:scale-95">Cancel</button>
                        <button @click="submitPayment" :disabled="savingPayment" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-green-600 text-white text-sm font-semibold hover:from-emerald-600 hover:to-green-700 hover:shadow-lg transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg v-if="!savingPayment" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{ savingPayment ? 'Saving...' : 'Record Payment' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- PAYMENT HISTORY MODAL -->
        <Teleport to="body">
            <div
                v-if="showHistoryModal"
                class="fixed inset-0 z-[1000] flex items-center justify-center p-2 sm:p-4 bg-slate-950/70 backdrop-blur-md overflow-y-auto"
                @click.self="closeHistoryModal"
            >
                <div class="relative w-full max-w-2xl my-4 sm:my-8 bg-white dark:bg-slate-800 rounded-2xl shadow-2xl overflow-hidden transition-colors duration-300">
                    <div class="relative bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 px-4 sm:px-6 py-4 sm:py-5">
                        <div class="relative flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center text-white font-bold text-base sm:text-lg border-2 border-white/30 shadow-lg flex-shrink-0 overflow-hidden">
                                    <img v-if="historyStudent?.photo" :src="'/storage/' + historyStudent.photo" class="w-full h-full object-cover" />
                                    <span v-else>{{ historyStudent?.name?.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-base sm:text-lg font-bold text-white truncate">Payment History</h3>
                                    <p class="text-[10px] sm:text-xs text-blue-100">
                                        {{ historyStudent?.name }} · {{ historyStudent?.student_id }}
                                    </p>
                                </div>
                            </div>
                            <button
                                @click="closeHistoryModal"
                                class="p-1.5 sm:p-2 rounded-lg text-white/80 hover:bg-white/20 transition-colors flex-shrink-0"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="bg-blue-50/50 dark:bg-blue-950/30 border-b border-blue-100 dark:border-blue-800 px-4 sm:px-6 py-4">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Payable</p>
                                <p class="text-base font-bold text-slate-800 dark:text-slate-100 mt-0.5">
                                    ৳ {{ historyStudent ? getRowTotals(historyStudent).payable.toLocaleString() : 0 }}
                                </p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider">Paid</p>
                                <p class="text-base font-bold text-cyan-700 dark:text-cyan-300 mt-0.5">
                                    ৳ {{ historyStudent ? getStudentPaid(historyStudent).toLocaleString() : 0 }}
                                </p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Due</p>
                                <p class="text-base font-bold text-amber-700 dark:text-amber-300 mt-0.5">
                                    ৳ {{ historyStudent ? getStudentDue(historyStudent).toLocaleString() : 0 }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 sm:p-6 max-h-[55vh] overflow-y-auto">
                        <div v-if="!historyStudent?.payment_logs || historyStudent.payment_logs.length === 0" class="text-center py-10">
                            <div class="inline-flex p-4 rounded-2xl bg-slate-50 dark:bg-slate-900 mb-3">
                                <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                            </div>
                            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">No payments yet</p>
                            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">This student hasn't made any payments.</p>
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="(log, idx) in historyStudent.payment_logs"
                                :key="log.id"
                                class="relative flex items-start gap-3 p-4 rounded-xl border-2 border-blue-100 dark:border-blue-800 bg-gradient-to-r from-blue-50/30 to-indigo-50/30 dark:from-blue-950/30 dark:to-indigo-950/30 hover:shadow-md transition-all"
                            >
                                <div class="flex flex-col items-center flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-bold text-xs shadow-md">
                                        {{ historyStudent.payment_logs.length - idx }}
                                    </div>
                                    <div v-if="idx < historyStudent.payment_logs.length - 1" class="w-0.5 bg-blue-200 dark:bg-blue-800 mt-1" style="min-height: 20px;"></div>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2 flex-wrap">
                                        <div>
                                            <p class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100">
                                                ৳ {{ Number(log.amount).toLocaleString() }}
                                            </p>
                                            <p class="text-[10px] sm:text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                {{ formatDateTime(log.paid_at) }}
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">After</p>
                                            <p class="text-sm font-bold text-emerald-700 dark:text-emerald-300">
                                                ৳ {{ Number(log.total_after || 0).toLocaleString() }}
                                            </p>
                                        </div>
                                    </div>

                                    <p v-if="log.note" class="mt-2 text-xs text-slate-600 dark:text-slate-300 bg-white/70 dark:bg-slate-900/70 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5">
                                        💬 {{ log.note }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 px-4 sm:px-6 py-4 bg-blue-50/30 dark:bg-blue-950/20 border-t border-blue-100 dark:border-blue-800">
                        <div class="text-[10px] sm:text-xs text-blue-700 dark:text-blue-400">
                            📊 Total {{ historyStudent?.payment_logs?.length || 0 }} payment(s) recorded
                        </div>
                        <button
                            @click="closeHistoryModal"
                            class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-sm font-semibold hover:from-blue-600 hover:to-indigo-700 hover:shadow-lg transition-all active:scale-95"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>