<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, router, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onBeforeUnmount } from 'vue'

// ============================================================
// PROPS
// ============================================================
const props = defineProps({
    classes: { type: Array, default: () => [] },
    existingFeeStructures: { type: Array, default: () => [] },
    classGroups: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    availableYears: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ year: '', search: '' }) },
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
// YEAR FILTER STATE (from URL)
// ============================================================
const selectedYear = ref(props.filters.year || new Date().getFullYear())
const yearInput = ref(selectedYear.value)
const showYearDropdown = ref(false)

// Search state
const search = ref(props.filters.search || '')
let searchTimeout = null

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

// ✅ Sync with URL when props change
watch(() => props.filters.year, (newYear) => {
    if (newYear && newYear !== selectedYear.value) {
        selectedYear.value = String(newYear)
        yearInput.value = String(newYear)
    }
})

watch(() => props.filters.search, (newSearch) => {
    if (newSearch !== search.value) {
        search.value = newSearch || ''
    }
})

// ============================================================
// APPLY FILTERS (backend)
// ============================================================
const applyFilters = (extra = {}) => {
    router.get(route('fees.create'), {
        year: selectedYear.value,
        search: search.value || undefined,
        page: 1,
        ...extra,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}

const applyYear = () => {
    const year = String(yearInput.value).trim()
    if (!/^\d{4}$/.test(year)) {
        yearInput.value = selectedYear.value
        showYearDropdown.value = false
        return
    }
    const numYear = parseInt(year)
    if (numYear < 2000 || numYear > 2100) {
        yearInput.value = selectedYear.value
        showYearDropdown.value = false
        return
    }
    if (String(numYear) === String(selectedYear.value)) {
        showYearDropdown.value = false
        return
    }
    selectedYear.value = String(numYear)
    showYearDropdown.value = false
    applyFilters({ year: numYear })
}

const selectYearSuggestion = (year) => {
    yearInput.value = year
    selectedYear.value = String(year)
    showYearDropdown.value = false
    applyFilters({ year })
}

const incrementYear = () => {
    yearInput.value = parseInt(yearInput.value || selectedYear.value) + 1
    applyYear()
}

const decrementYear = () => {
    yearInput.value = parseInt(yearInput.value || selectedYear.value) - 1
    applyYear()
}

// ✅ Debounced search
watch(search, () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        applyFilters()
    }, 500)
})

onBeforeUnmount(() => {
    clearTimeout(searchTimeout)
})

const resetSearch = () => {
    search.value = ''
    applyFilters()
}

// ============================================================
// VIEW STATE
// ============================================================
const activeGroupKey = ref(null)

// ============================================================
// MODAL STATE
// ============================================================
const showModal = ref(false)

const openModal = () => {
    form.academic_year = String(selectedYear.value)
    showModal.value = true
    document.body.style.overflow = 'hidden'
}

const closeModal = () => {
    showModal.value = false
    document.body.style.overflow = ''
    form.reset()
    form.clearErrors()
    form.fee_types = []
    form.academic_year = String(selectedYear.value)
}

// ============================================================
// FEE TYPE PRESETS
// ============================================================
const feeTypePresets = [
    { name: 'Monthly Fee',   icon: '📚', color: 'from-blue-500 to-indigo-600',   cycle: 'Monthly' },
    { name: 'Exam Fee',      icon: '📝', color: 'from-purple-500 to-pink-600',   cycle: 'Term-wise' },
    { name: 'Transport Fee', icon: '🚌', color: 'from-amber-500 to-orange-600',  cycle: 'Monthly' },
    { name: 'Library Fee',   icon: '📖', color: 'from-emerald-500 to-teal-600',  cycle: 'Yearly' },
    { name: 'Admission Fee', icon: '🎓', color: 'from-rose-500 to-red-600',      cycle: 'One-time' },
    { name: 'Lab Fee',       icon: '🔬', color: 'from-cyan-500 to-blue-600',     cycle: 'Monthly' },
    { name: 'Sports Fee',    icon: '⚽', color: 'from-lime-500 to-green-600',    cycle: 'Yearly' },
    { name: 'Other Fee',     icon: '💰', color: 'from-slate-500 to-slate-700',   cycle: 'One-time' },
]

// ============================================================
// CYCLE OPTIONS
// ============================================================
const cycleOptions = [
    { value: 'Monthly',     label: 'Monthly',     perYear: 12 },
    { value: 'Quarterly',   label: 'Quarterly',   perYear: 4 },
    { value: 'Half-Yearly', label: 'Half-Yearly', perYear: 2 },
    { value: 'Term-wise',   label: 'Term-wise',   perYear: 3 },
    { value: 'Yearly',      label: 'Yearly',      perYear: 1 },
    { value: 'One-time',    label: 'One-time',    perYear: 1 },
]

const getCycleInfo = (cycle) =>
    cycleOptions.find(c => c.value === cycle) || cycleOptions[0]

// ============================================================
// CREATE FORM
// ============================================================
const form = useForm({
    class_id: '',
    academic_year: String(new Date().getFullYear()),
    fee_types: [],
})

// Classes filtered by selected year (for Create Modal dropdown)
const classesForYear = computed(() => {
    return props.classes.filter(c => String(c.academic_year) === String(selectedYear.value))
})

const selectedClass = computed(() => {
    if (!form.class_id) return null
    return props.classes.find(c => c.id === Number(form.class_id))
})

const uniqueClasses = computed(() => {
    return classesForYear.value
        .map(cls => ({
            id: cls.id,
            class_name: cls.class_name,
            section: cls.section,
            academic_year: cls.academic_year,
            display_name: cls.section
                ? `${cls.class_name} — Section ${cls.section}`
                : cls.class_name,
        }))
        .sort((a, b) => {
            if (a.class_name !== b.class_name) return a.class_name.localeCompare(b.class_name)
            return (a.section || '').localeCompare(b.section || '')
        })
})

const totals = computed(() => {
    const active = form.fee_types.filter(f => f.is_active)
    let oneTime = 0, monthlyRecurring = 0, yearlyRecurring = 0, annualizedRecurring = 0

    active.forEach(f => {
        const amount = Number(f.amount || 0)
        const cycleInfo = getCycleInfo(f.cycle)
        if (f.cycle === 'One-time') oneTime += amount
        else if (f.cycle === 'Monthly') { monthlyRecurring += amount; annualizedRecurring += amount * 12 }
        else if (f.cycle === 'Yearly') { yearlyRecurring += amount; annualizedRecurring += amount }
        else annualizedRecurring += amount * cycleInfo.perYear
    })

    return {
        oneTime, monthlyRecurring, yearlyRecurring, annualizedRecurring,
        annualTotal: oneTime + annualizedRecurring,
    }
})

const getAnnualAmount = (fee) => {
    const amount = Number(fee.amount || 0)
    return amount * getCycleInfo(fee.cycle).perYear
}

const duplicateWarning = computed(() => {
    if (!form.class_id || !form.academic_year) return null
    const cls = props.classes.find(c => c.id === Number(form.class_id))
    if (!cls) return null
    return props.existingFeeStructures.find(ext =>
        ext.class_name === cls.class_name &&
        (ext.section || 'N/A') === (cls.section || 'N/A') &&
        String(ext.academic_year) === String(form.academic_year)
    ) || null
})

// ============================================================
// CREATE FORM METHODS
// ============================================================
const addFeeType = (preset = null) => {
    if (preset) {
        const exists = form.fee_types.some(
            f => f.name.toLowerCase() === preset.name.toLowerCase()
        )
        if (exists) {
            showToast(`"${preset.name}" already added`, 'warning')
            return
        }
        form.fee_types.push({
            name: preset.name,
            amount: 0,
            cycle: preset.cycle || 'Monthly',
            is_active: true,
            icon: preset.icon,
            color: preset.color,
        })
    } else {
        form.fee_types.push({
            name: '',
            amount: 0,
            cycle: 'Monthly',
            is_active: true,
            icon: '💰',
            color: 'from-slate-500 to-slate-700',
        })
    }
}

const removeFeeType = (index) => form.fee_types.splice(index, 1)
const toggleFeeType = (index) => {
    form.fee_types[index].is_active = !form.fee_types[index].is_active
}

const submitForm = () => {
    if (!form.class_id) return showToast('Please select a class', 'error')
    if (!form.academic_year) return showToast('Please select an academic year', 'error')
    if (form.fee_types.length === 0) return showToast('Please add at least one fee type', 'error')

    const missingName = form.fee_types.some(f => !f.name || !f.name.trim())
    if (missingName) return showToast('Please fill all fee type names', 'error')

    const invalidAmount = form.fee_types.some(
        f => f.amount === null || f.amount === '' || Number(f.amount) <= 0
    )
    if (invalidAmount) return showToast('Each fee amount must be greater than 0', 'error')

    if (duplicateWarning.value) {
        const cls = props.classes.find(c => c.id === Number(form.class_id))
        const ok = confirm(
            `⚠️ Fee structure already exists for ${cls?.class_name} - Section ${cls?.section} — Academic Year ${form.academic_year}.\n\nDo you want to overwrite/update it?`
        )
        if (!ok) return
    }

    // ✅ Preserve filters + page in URL
    form.transform((data) => ({
        ...data,
        _year: selectedYear.value,
        _search: search.value,
        _page: props.classGroups.current_page || 1,
    })).post(route('fees.create.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            showToast(page.props.flash?.success || 'Fee structure saved successfully! 🎉', 'success')
            if (form.class_id) {
                const cls = props.classes.find(c => c.id === Number(form.class_id))
                if (cls) {
                    activeGroupKey.value = `${cls.class_name}__${cls.section || 'N/A'}`
                }
            }
            closeModal()
        },
        onError: (errors) => {
            const first = Object.values(errors)[0]
            showToast(first || 'Please fix the errors', 'error')
        },
    })
}

watch(() => form.class_id, (newId) => {
    if (newId) {
        const cls = props.classes.find(c => c.id === Number(newId))
        if (cls) form.academic_year = String(cls.academic_year)
    }
})

// ============================================================
// CLASS GROUPS (from paginator)
// ============================================================
const classGroups = computed(() => props.classGroups?.data || [])

const activeGroup = computed(() =>
    classGroups.value.find(g => g.key === activeGroupKey.value) || null
)

const openGroup = (group) => {
    activeGroupKey.value = group.key
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

const backToList = () => {
    activeGroupKey.value = null
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

watch(() => props.filters.year, () => {
    activeGroupKey.value = null
})

// ============================================================
// EDIT MODAL
// ============================================================
const showEditModal = ref(false)
const editForm = useForm({
    class_id: '',
    academic_year: '',
    fee_types: [],
})

const editingGroup = ref(null)

const openEditModal = (yearGroup, parentGroup) => {
    editingGroup.value = {
        class_id: parentGroup.class_id,
        class_name: parentGroup.class_name,
        section: parentGroup.section,
        academic_year: parentGroup.academic_year,
    }

    editForm.class_id = parentGroup.class_id
    editForm.academic_year = parentGroup.academic_year
    editForm.fee_types = parentGroup.fees.map(f => ({
        id: f.id,
        name: f.name,
        amount: f.amount,
        cycle: f.cycle,
        is_active: f.is_active,
        icon: guessIcon(f.name),
        color: guessColor(f.name),
    }))
    editForm.clearErrors()
    showEditModal.value = true
    document.body.style.overflow = 'hidden'
}

const closeEditModal = () => {
    showEditModal.value = false
    editingGroup.value = null
    document.body.style.overflow = ''
    editForm.reset()
    editForm.clearErrors()
}

const guessIcon = (name) => {
    const n = (name || '').toLowerCase()
    if (n.includes('month')) return '📚'
    if (n.includes('exam')) return '📝'
    if (n.includes('transport')) return '🚌'
    if (n.includes('library')) return '📖'
    if (n.includes('admission')) return '🎓'
    if (n.includes('lab')) return '🔬'
    if (n.includes('sport')) return '⚽'
    return '💰'
}

const guessColor = (name) => {
    const n = (name || '').toLowerCase()
    if (n.includes('month')) return 'from-blue-500 to-indigo-600'
    if (n.includes('exam')) return 'from-purple-500 to-pink-600'
    if (n.includes('transport')) return 'from-amber-500 to-orange-600'
    if (n.includes('library')) return 'from-emerald-500 to-teal-600'
    if (n.includes('admission')) return 'from-rose-500 to-red-600'
    if (n.includes('lab')) return 'from-cyan-500 to-blue-600'
    if (n.includes('sport')) return 'from-lime-500 to-green-600'
    return 'from-slate-500 to-slate-700'
}

const addEditFeeType = () => {
    editForm.fee_types.push({
        id: null,
        name: '',
        amount: 0,
        cycle: 'Monthly',
        is_active: true,
        icon: '💰',
        color: 'from-slate-500 to-slate-700',
    })
}

const removeEditFeeType = (index) => editForm.fee_types.splice(index, 1)

const submitEdit = () => {
    if (editForm.fee_types.length === 0) {
        return showToast('At least one fee type required', 'error')
    }
    const missingName = editForm.fee_types.some(f => !f.name || !f.name.trim())
    if (missingName) return showToast('Please fill all fee names', 'error')

    const invalidAmount = editForm.fee_types.some(
        f => f.amount === null || f.amount === '' || Number(f.amount) <= 0
    )
    if (invalidAmount) return showToast('All amounts must be > 0', 'error')

    editForm.post(route('fees.create.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            showToast(page.props.flash?.success || 'Fee structure updated! ✏️', 'success')
            closeEditModal()
        },
        onError: (errors) => {
            const first = Object.values(errors)[0]
            showToast(first || 'Update failed', 'error')
        },
    })
}

// ============================================================
// DELETE
// ============================================================
const deleteGroup = (yearGroup, parentGroup) => {
    const ok = confirm(
        `⚠️ Delete fee structure for ${parentGroup.class_name} - Section ${parentGroup.section} — ${parentGroup.academic_year}?\n\nThis will remove all fee types for this class & year.`
    )
    if (!ok) return

    router.delete(
        route('fees.destroy-group', {
            class_id: parentGroup.class_id,
            academic_year: parentGroup.academic_year,
            year: selectedYear.value,
            search: search.value || undefined,
            page: props.classGroups.current_page || 1,
        }),
        {
            preserveScroll: true,
            onSuccess: (page) => {
                showToast(page.props.flash?.success || 'Fee structure deleted 🗑️', 'success')
                activeGroupKey.value = null
            },
            onError: () => {
                showToast('Failed to delete fee structure', 'error')
            },
        }
    )
}

// ============================================================
// HELPERS
// ============================================================
const formatCurrency = (n) => Number(n || 0).toLocaleString()

const groupYearTotal = (group) => {
    return (group.fees || [])
        .filter(f => f.is_active)
        .reduce((sum, f) => sum + f.amount * getCycleInfo(f.cycle).perYear, 0)
}

const groupFeeCategories = (group) => {
    const set = new Set()
    ;(group.fees || []).forEach(f => set.add(f.name))
    return Array.from(set)
}

const yearAnnualTotal = () => {
    if (!activeGroup.value) return 0
    return groupYearTotal(activeGroup.value)
}
</script>

<template>
    <Head title="Fee Structures" />

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
            <template v-if="!activeGroup">
                <!-- Page Header -->
                <div class="mb-4 sm:mb-6">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl shadow-lg shadow-emerald-500/25 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4 1.343 4 3-1.79 3-4 3m0-12V5m0 14v-2M5 12h14" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-emerald-800 to-teal-800 dark:from-emerald-300 dark:to-teal-300 bg-clip-text text-transparent truncate">
                                    Fee Structures
                                </h1>
                                <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 flex items-center gap-2 mt-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    Class-wise fee structure management
                                </p>
                            </div>
                        </div>

                        <button
                            @click="openModal"
                            class="inline-flex items-center justify-center gap-2 px-4 sm:px-6 py-2.5 sm:py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-sm font-bold text-white hover:from-emerald-600 hover:to-teal-700 hover:shadow-lg hover:shadow-emerald-500/25 transition-all active:scale-95 w-full sm:w-auto"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                            </svg>
                            Create Fee Structure
                        </button>
                    </div>
                </div>

                <!-- FILTERS: Year + Search -->
                <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm p-4 sm:p-5 transition-colors duration-300">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                        <!-- Year Filter -->
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                            <div class="flex items-center gap-2">
                                <div class="p-2 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex-shrink-0">
                                    <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div class="hidden sm:block">
                                    <h3 class="text-xs font-bold text-emerald-800 dark:text-emerald-200">Academic Year</h3>
                                </div>
                            </div>

                            <div class="relative flex items-center gap-2 flex-1 w-full sm:w-auto">
                                <button
                                    type="button"
                                    @click="decrementYear"
                                    class="p-2 rounded-lg border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 hover:border-emerald-400 transition-colors active:scale-95 flex-shrink-0"
                                    title="Previous year"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                    </svg>
                                </button>

                                <div class="relative flex-1" @focusout="setTimeout(() => showYearDropdown = false, 200)">
                                    <input
                                        v-model="yearInput"
                                        @focus="showYearDropdown = true"
                                        @keydown.enter.prevent="applyYear"
                                        @keydown.esc="showYearDropdown = false"
                                        type="text"
                                        inputmode="numeric"
                                        pattern="[0-9]*"
                                        maxlength="4"
                                        placeholder="YYYY"
                                        class="w-full rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 pl-4 pr-10 py-2 text-sm font-bold text-emerald-900 dark:text-emerald-100 text-center tracking-wider outline-none cursor-text focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10"
                                    />

                                    <button
                                        type="button"
                                        @click="showYearDropdown = !showYearDropdown"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-md text-emerald-500 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 transition-colors"
                                    >
                                        <svg
                                            class="w-4 h-4 transition-transform duration-200"
                                            :class="showYearDropdown ? 'rotate-180' : ''"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>

                                    <div
                                        v-if="showYearDropdown && filteredYearSuggestions.length > 0"
                                        class="absolute top-full left-0 right-0 mt-2 bg-white dark:bg-slate-800 rounded-xl border-2 border-emerald-200 dark:border-emerald-800 shadow-xl z-50 overflow-hidden"
                                    >
                                        <div class="max-h-60 overflow-y-auto py-1">
                                            <button
                                                v-for="year in filteredYearSuggestions"
                                                :key="year"
                                                type="button"
                                                @mousedown.prevent="selectYearSuggestion(year)"
                                                class="w-full text-left px-4 py-2 text-sm font-medium transition-colors flex items-center justify-between"
                                                :class="String(year) === String(selectedYear)
                                                    ? 'bg-emerald-600 text-white'
                                                    : 'text-emerald-800 dark:text-emerald-200 hover:bg-emerald-50 dark:hover:bg-emerald-950/50'"
                                            >
                                                <span>{{ year }}</span>
                                                <span
                                                    v-if="year === new Date().getFullYear()"
                                                    class="text-[9px] px-1.5 py-0.5 rounded-full font-bold"
                                                    :class="String(year) === String(selectedYear)
                                                        ? 'bg-white/20 text-white'
                                                        : 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'"
                                                >
                                                    NOW
                                                </span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click="incrementYear"
                                    class="p-2 rounded-lg border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 hover:border-emerald-400 transition-colors active:scale-95 flex-shrink-0"
                                    title="Next year"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Search -->
                        <div class="flex items-center gap-2">
                            <div class="p-2 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex-shrink-0">
                                <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                            <div class="relative flex-1">
                                <input
                                    v-model="search"
                                    type="text"
                                    placeholder="Search class or section..."
                                    class="w-full rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 pl-4 pr-10 py-2 text-sm text-emerald-900 dark:text-emerald-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10"
                                />
                                <button
                                    v-if="search"
                                    type="button"
                                    @click="resetSearch"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-md text-emerald-500 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 transition-colors"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Active filter chips -->
                    <div v-if="search" class="mt-3 flex flex-wrap items-center gap-2 pt-3 border-t border-emerald-100 dark:border-emerald-800">
                        <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">Active:</span>
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs font-medium">
                            Search: "{{ search }}"
                            <button @click="resetSearch" class="hover:text-emerald-900 dark:hover:text-emerald-100">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </span>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="classGroups.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm text-center transition-colors duration-300">
                    <svg class="w-12 h-12 sm:w-16 sm:h-16 text-emerald-300 dark:text-emerald-700 mx-auto mb-3 sm:mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4 1.343 4 3-1.79 3-4 3m0-12V5m0 14v-2M5 12h14" />
                    </svg>
                    <h3 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200 mb-2">
                        {{ search ? 'No Matching Classes' : `No Fee Structures for ${selectedYear}` }}
                    </h3>
                    <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 mb-4">
                        {{ search 
                            ? `No classes match "${search}" for year ${selectedYear}.` 
                            : `No fee structures exist for year ${selectedYear}. Click "Create Fee Structure" to add one.` }}
                    </p>
                    <button
                        v-if="search"
                        @click="resetSearch"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-sm font-semibold hover:bg-emerald-200 dark:hover:bg-emerald-900/60 transition-all"
                    >
                        Clear Search
                    </button>
                </div>

                <!-- Class Cards Grid -->
                <div v-else>
                    <div class="mb-4 flex items-center gap-2 flex-wrap">
                        <h2 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200">
                            📚 Classes for {{ selectedYear }}
                        </h2>
                        <span class="text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300 px-2.5 py-1 rounded-full font-semibold">
                            {{ classGroups.length }} of {{ classGroups.total || classGroups.length }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                        <button
                            v-for="group in classGroups"
                            :key="group.key"
                            @click="openGroup(group)"
                            class="group relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-6 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm hover:shadow-xl hover:border-emerald-400 dark:hover:border-emerald-600 transition-all duration-300 text-left overflow-hidden"
                        >
                            <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br from-emerald-400/20 to-teal-500/20 dark:from-emerald-600/20 dark:to-teal-700/20 group-hover:scale-150 transition-transform duration-500"></div>

                            <div class="relative">
                                <div class="flex items-start justify-between mb-3 sm:mb-4">
                                    <div class="p-2.5 sm:p-3 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/20">
                                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4 1.343 4 3-1.79 3-4 3m0-12V5m0 14v-2M5 12h14" />
                                        </svg>
                                    </div>

                                    <span class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">
                                        {{ group.totalFees }} fees
                                    </span>
                                </div>

                                <h3 class="text-lg sm:text-xl font-bold text-emerald-900 dark:text-emerald-100 mb-1 truncate">
                                    {{ group.class_name }}
                                </h3>
                                <p class="text-sm font-semibold text-teal-600 dark:text-teal-400 mb-1">
                                    Section: {{ group.section }}
                                </p>

                                <p class="text-xs text-emerald-500 dark:text-emerald-400 mb-3 flex items-center gap-1 font-medium">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    <span class="truncate">
                                        Year {{ group.academic_year }}
                                    </span>
                                </p>

                                <div class="flex flex-wrap gap-1 mb-3 sm:mb-4">
                                    <span
                                        v-for="(feeName, idx) in groupFeeCategories(group).slice(0, 3)"
                                        :key="idx"
                                        class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800"
                                    >
                                        {{ feeName }}
                                    </span>
                                    <span
                                        v-if="groupFeeCategories(group).length > 3"
                                        class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-teal-50 dark:bg-teal-900/40 text-teal-600 dark:text-teal-300 border border-teal-100 dark:border-teal-800"
                                    >
                                        +{{ groupFeeCategories(group).length - 3 }} more
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pt-3 sm:pt-4 border-t border-emerald-100 dark:border-emerald-800">
                                    <span class="text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4 1.343 4 3-1.79 3-4 3m0-12V5m0 14v-2M5 12h14" />
                                        </svg>
                                        ৳ {{ formatCurrency(groupYearTotal(group)) }}/yr
                                    </span>
                                    <span class="text-xs font-semibold text-teal-600 dark:text-teal-400 group-hover:text-teal-800 dark:group-hover:text-teal-200 transition-colors">
                                        View Fees →
                                    </span>
                                </div>
                            </div>
                        </button>
                    </div>

                    <!-- ✅ PAGINATION -->
                    <div
                        v-if="classGroups.links && classGroups.links.length > 3"
                        class="mt-6 bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm px-4 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-center justify-between gap-3 transition-colors duration-300"
                    >
                        <div class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 text-center sm:text-left">
                            Showing <span class="font-bold text-emerald-800 dark:text-emerald-200">{{ classGroups.from ?? 0 }}</span> to
                            <span class="font-bold text-emerald-800 dark:text-emerald-200">{{ classGroups.to ?? 0 }}</span> of
                            <span class="font-bold text-emerald-800 dark:text-emerald-200">{{ classGroups.total ?? 0 }}</span> classes
                        </div>

                        <div class="flex items-center gap-1 flex-wrap justify-center">
                            <template v-for="(link, index) in classGroups.links" :key="index">
                                <template v-if="link.url === null">
                                    <span
                                        class="px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-xs sm:text-sm font-medium text-emerald-300 dark:text-emerald-700 bg-emerald-50 dark:bg-emerald-950/40 cursor-not-allowed"
                                        v-html="link.label"
                                    ></span>
                                </template>
                                <template v-else>
                                    <Link
                                        :href="link.url"
                                        preserve-scroll
                                        preserve-state
                                        :class="[
                                            'px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-xs sm:text-sm font-medium transition-all duration-200',
                                            link.active
                                                ? 'bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-md shadow-emerald-500/25'
                                                : 'bg-white dark:bg-slate-800 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 hover:border-emerald-400'
                                        ]"
                                        v-html="link.label"
                                    />
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            <!-- VIEW 2: FEE DETAILS -->
            <template v-else>
                <div class="mb-4 sm:mb-6">
                    <button
                        @click="backToList"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-200 mb-3 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Classes
                    </button>

                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3 sm:gap-4 w-full sm:w-auto">
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl shadow-lg shadow-emerald-500/25 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-lg sm:text-2xl font-bold bg-gradient-to-r from-emerald-800 to-teal-800 dark:from-emerald-300 dark:to-teal-300 bg-clip-text text-transparent truncate">
                                    {{ activeGroup.class_name }} — {{ activeGroup.section }}
                                </h1>
                                <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 mt-0.5">
                                    Academic Year <b>{{ activeGroup.academic_year }}</b>
                                    <span class="mx-1">·</span>
                                    <span class="font-semibold">{{ activeGroup.totalFees }}</span> total fees
                                </p>
                            </div>
                        </div>

                        <button
                            @click="openModal"
                            class="inline-flex items-center justify-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-sm font-bold text-white hover:from-emerald-600 hover:to-teal-700 hover:shadow-lg shadow-emerald-500/20 transition-all active:scale-95 w-full sm:w-auto"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                            </svg>
                            Add Fee Structure
                        </button>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm overflow-hidden transition-colors duration-300">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 sm:px-6 py-4 bg-gradient-to-r from-emerald-50/50 to-teal-50/50 dark:from-emerald-950/30 dark:to-teal-950/30 border-b border-emerald-100 dark:border-emerald-800">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="inline-flex items-center gap-1.5 text-sm font-bold text-emerald-800 dark:text-emerald-200 bg-white dark:bg-slate-800 border border-emerald-200 dark:border-emerald-700 px-3 py-1.5 rounded-lg">
                                    <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    Year {{ activeGroup.academic_year }}
                                </span>
                                <span class="text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 px-2.5 py-1 rounded-full">
                                    {{ activeGroup.fees.length }} fees
                                </span>
                                <span class="text-[10px] font-bold bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 px-2.5 py-1 rounded-full">
                                    Annual: ৳ {{ formatCurrency(groupYearTotal(activeGroup)) }}
                                </span>
                            </div>

                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button
                                    @click="openEditModal(null, activeGroup)"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900/60 border border-blue-200 dark:border-blue-800 text-xs font-bold transition-all"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit
                                </button>
                                <button
                                    @click="deleteGroup(null, activeGroup)"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-50 dark:bg-rose-900/40 text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/60 border border-rose-200 dark:border-rose-800 text-xs font-bold transition-all"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Delete
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[640px] border-collapse text-xs sm:text-sm">
                                <thead>
                                    <tr class="bg-emerald-800 dark:bg-emerald-900 text-white">
                                        <th class="border border-emerald-800 dark:border-emerald-900 px-3 sm:px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider">Fee Name</th>
                                        <th class="border border-emerald-800 dark:border-emerald-900 px-3 sm:px-4 py-2 text-right text-[10px] font-bold uppercase tracking-wider">Amount</th>
                                        <th class="border border-emerald-800 dark:border-emerald-900 px-3 sm:px-4 py-2 text-center text-[10px] font-bold uppercase tracking-wider">Cycle</th>
                                        <th class="border border-emerald-800 dark:border-emerald-900 px-3 sm:px-4 py-2 text-right text-[10px] font-bold uppercase tracking-wider">Annual</th>
                                        <th class="border border-emerald-800 dark:border-emerald-900 px-3 sm:px-4 py-2 text-center text-[10px] font-bold uppercase tracking-wider">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(fee, idx) in activeGroup.fees"
                                        :key="fee.id"
                                        :class="idx % 2 === 0 ? 'bg-white dark:bg-slate-900' : 'bg-emerald-50/30 dark:bg-emerald-950/20'"
                                    >
                                        <td class="border border-emerald-200 dark:border-emerald-800 px-3 sm:px-4 py-2.5 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ fee.name }}</td>
                                        <td class="border border-emerald-200 dark:border-emerald-800 px-3 sm:px-4 py-2.5 text-right text-sm font-bold text-slate-800 dark:text-slate-100">
                                            ৳ {{ formatCurrency(fee.amount) }}
                                        </td>
                                        <td class="border border-emerald-200 dark:border-emerald-800 px-3 sm:px-4 py-2.5 text-center">
                                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                                                {{ fee.cycle }}
                                            </span>
                                        </td>
                                        <td class="border border-emerald-200 dark:border-emerald-800 px-3 sm:px-4 py-2.5 text-right text-sm font-bold text-teal-700 dark:text-teal-300">
                                            ৳ {{ formatCurrency(fee.amount * getCycleInfo(fee.cycle).perYear) }}
                                        </td>
                                        <td class="border border-emerald-200 dark:border-emerald-800 px-3 sm:px-4 py-2.5 text-center">
                                            <span
                                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold"
                                                :class="fee.is_active
                                                    ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700'
                                                    : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 border border-slate-300 dark:border-slate-600'"
                                            >
                                                <span class="w-1.5 h-1.5 rounded-full" :class="fee.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                                {{ fee.is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- CREATE MODAL -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="showModal"
                    class="fixed inset-0 z-[9998] bg-slate-900/60 dark:bg-black/70 backdrop-blur-sm overflow-y-auto"
                    @click.self="closeModal"
                >
                    <div class="min-h-full flex items-start sm:items-center justify-center p-2 sm:p-4">
                        <div class="w-full max-w-5xl bg-white dark:bg-slate-800 rounded-2xl shadow-2xl overflow-hidden my-4 sm:my-8 transition-colors duration-300">

                            <div class="sticky top-0 z-10 bg-gradient-to-r from-emerald-600 to-teal-600 px-4 sm:px-6 py-4 flex items-center justify-between">
                                <h2 class="text-base sm:text-lg font-bold text-white flex items-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                    Create Fee Structure
                                </h2>
                                <button
                                    @click="closeModal"
                                    class="p-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <div class="p-4 sm:p-6 space-y-5 max-h-[calc(100vh-180px)] overflow-y-auto">
                                <!-- STEP 1 -->
                                <div class="p-4 rounded-xl bg-gradient-to-br from-emerald-50 to-green-50 dark:from-emerald-950/40 dark:to-green-950/40 border border-emerald-200 dark:border-emerald-800 transition-colors duration-300">
                                    <h3 class="text-sm font-bold text-emerald-800 dark:text-emerald-200 mb-3 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-lg bg-emerald-500 text-white flex items-center justify-center text-xs">1</span>
                                        Class & Academic Year
                                    </h3>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                                Class & Section <span class="text-rose-500">*</span>
                                            </label>
                                            <select
                                                v-model="form.class_id"
                                                class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 cursor-pointer"
                                            >
                                                <option value="">-- Choose a class --</option>
                                                <option
                                                    v-for="cls in uniqueClasses"
                                                    :key="cls.id"
                                                    :value="cls.id"
                                                >
                                                    {{ cls.display_name }}
                                                </option>
                                            </select>
                                            <p v-if="form.errors.class_id" class="mt-1 text-xs text-rose-500 dark:text-rose-400">{{ form.errors.class_id }}</p>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                                Academic Year <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                v-model="form.academic_year"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="4"
                                                placeholder="YYYY"
                                                class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2.5 text-sm font-bold text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"
                                            />
                                        </div>
                                    </div>

                                    <div
                                        v-if="duplicateWarning"
                                        class="mt-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 flex items-start gap-2"
                                    >
                                        <span class="text-lg flex-shrink-0">⚠️</span>
                                        <p class="text-xs text-amber-800 dark:text-amber-200">
                                            Already exists for <b>{{ selectedClass?.class_name }} - Section {{ selectedClass?.section }} ({{ form.academic_year }})</b>.
                                            Saving will overwrite.
                                        </p>
                                    </div>
                                </div>

                                <!-- STEP 2 -->
                                <div class="p-4 rounded-xl bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-950/40 dark:to-indigo-950/40 border border-blue-200 dark:border-blue-800 transition-colors duration-300">
                                    <h3 class="text-sm font-bold text-blue-800 dark:text-blue-200 mb-3 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-lg bg-blue-500 text-white flex items-center justify-center text-xs">2</span>
                                        Fee Types
                                        <span class="ml-auto text-[10px] bg-blue-200 dark:bg-blue-900/60 text-blue-800 dark:text-blue-200 px-2 py-0.5 rounded-full">{{ form.fee_types.length }} added</span>
                                    </h3>

                                    <div class="mb-3">
                                        <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Quick Add</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button
                                                v-for="preset in feeTypePresets"
                                                :key="preset.name"
                                                type="button"
                                                @click="addFeeType(preset)"
                                                :class="`inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gradient-to-r ${preset.color} text-white text-[11px] font-semibold hover:shadow-md active:scale-95 transition-all`"
                                            >
                                                <span>{{ preset.icon }}</span>
                                                <span>{{ preset.name }}</span>
                                                <span class="text-white/80 font-bold">+</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="form.fee_types.length === 0" class="text-center py-6 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl bg-white/60 dark:bg-slate-900/40">
                                        <p class="text-3xl mb-1">💰</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">No fee types added</p>
                                    </div>

                                    <div v-else class="space-y-2">
                                        <div
                                            v-for="(fee, index) in form.fee_types"
                                            :key="index"
                                            class="p-3 rounded-xl bg-white dark:bg-slate-900 border-2 border-slate-200 dark:border-slate-700"
                                            :class="!fee.is_active ? 'opacity-60' : ''"
                                        >
                                            <div class="flex items-start gap-2">
                                                <div :class="`w-9 h-9 rounded-lg bg-gradient-to-br ${fee.color} flex items-center justify-center text-base shadow-sm flex-shrink-0`">
                                                    {{ fee.icon }}
                                                </div>
                                                <div class="flex-1 grid grid-cols-1 sm:grid-cols-12 gap-2">
                                                    <div class="sm:col-span-4">
                                                        <input
                                                            v-model="fee.name"
                                                            type="text"
                                                            placeholder="Fee name"
                                                            class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5 text-xs font-medium text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/10"
                                                        />
                                                    </div>
                                                    <div class="sm:col-span-3">
                                                        <div class="relative">
                                                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400 dark:text-slate-500 font-bold">৳</span>
                                                            <input
                                                                v-model.number="fee.amount"
                                                                type="number"
                                                                min="0"
                                                                class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 pl-6 pr-2 py-1.5 text-xs font-bold text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/10"
                                                            />
                                                        </div>
                                                    </div>
                                                    <div class="sm:col-span-3">
                                                        <select
                                                            v-model="fee.cycle"
                                                            class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1.5 text-xs font-medium text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/10 cursor-pointer"
                                                        >
                                                            <option v-for="opt in cycleOptions" :key="opt.value" :value="opt.value">
                                                                {{ opt.label }}
                                                            </option>
                                                        </select>
                                                    </div>
                                                    <div class="sm:col-span-2 flex items-center gap-1">
                                                        <button
                                                            type="button"
                                                            @click="toggleFeeType(index)"
                                                            class="flex-1 inline-flex items-center justify-center gap-1 px-2 py-1.5 rounded-lg text-[10px] font-bold transition-all"
                                                            :class="fee.is_active
                                                                ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700'
                                                                : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 border border-slate-300 dark:border-slate-600'"
                                                        >
                                                            {{ fee.is_active ? 'On' : 'Off' }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            @click="removeFeeType(index)"
                                                            class="p-1.5 rounded-lg bg-rose-50 dark:bg-rose-900/40 text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/60 border border-rose-200 dark:border-rose-800 transition-all"
                                                        >
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-1.5 pl-11 text-[10px] text-slate-400 dark:text-slate-500">
                                                Annual: ৳ {{ formatCurrency(getAnnualAmount(fee)) }} (×{{ getCycleInfo(fee.cycle).perYear }})
                                            </div>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        @click="addFeeType()"
                                        class="mt-3 w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 text-xs font-semibold text-slate-600 dark:text-slate-400 hover:border-emerald-500 hover:text-emerald-700 dark:hover:text-emerald-300 hover:bg-white dark:hover:bg-slate-900 transition-all"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                                        </svg>
                                        Add Custom Fee Type
                                    </button>
                                </div>

                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <div class="p-3 rounded-xl bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800">
                                        <p class="text-[9px] font-bold text-blue-600 dark:text-blue-400 uppercase">Monthly</p>
                                        <p class="text-base font-bold text-blue-700 dark:text-blue-300">৳ {{ formatCurrency(totals.monthlyRecurring) }}</p>
                                    </div>
                                    <div class="p-3 rounded-xl bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800">
                                        <p class="text-[9px] font-bold text-purple-600 dark:text-purple-400 uppercase">Yearly</p>
                                        <p class="text-base font-bold text-purple-700 dark:text-purple-300">৳ {{ formatCurrency(totals.yearlyRecurring) }}</p>
                                    </div>
                                    <div class="p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800">
                                        <p class="text-[9px] font-bold text-rose-600 dark:text-rose-400 uppercase">One-time</p>
                                        <p class="text-base font-bold text-rose-700 dark:text-rose-300">৳ {{ formatCurrency(totals.oneTime) }}</p>
                                    </div>
                                    <div class="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border-2 border-emerald-300 dark:border-emerald-700">
                                        <p class="text-[9px] font-bold text-emerald-700 dark:text-emerald-300 uppercase">Annual Total</p>
                                        <p class="text-base font-bold text-emerald-700 dark:text-emerald-300">৳ {{ formatCurrency(totals.annualTotal) }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="sticky bottom-0 bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-2 transition-colors duration-300">
                                <button
                                    @click="closeModal"
                                    class="px-4 py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-all active:scale-95"
                                >
                                    Cancel
                                </button>
                                <button
                                    @click="submitForm"
                                    :disabled="form.processing"
                                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-sm font-bold text-white hover:from-emerald-600 hover:to-teal-700 hover:shadow-lg transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <svg v-if="!form.processing" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    {{ form.processing ? 'Saving...' : 'Save Fee Structure' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- EDIT MODAL -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="showEditModal"
                    class="fixed inset-0 z-[9998] bg-slate-900/60 dark:bg-black/70 backdrop-blur-sm overflow-y-auto"
                    @click.self="closeEditModal"
                >
                    <div class="min-h-full flex items-start sm:items-center justify-center p-2 sm:p-4">
                        <div class="w-full max-w-4xl bg-white dark:bg-slate-800 rounded-2xl shadow-2xl overflow-hidden my-4 sm:my-8 transition-colors duration-300">

                            <div class="sticky top-0 z-10 bg-gradient-to-r from-blue-600 to-indigo-600 px-4 sm:px-6 py-4 flex items-center justify-between">
                                <div>
                                    <h2 class="text-base sm:text-lg font-bold text-white flex items-center gap-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                        Edit Fee Structure
                                    </h2>
                                    <p v-if="editingGroup" class="text-xs text-white/80 mt-0.5">
                                        {{ editingGroup.class_name }} · Section {{ editingGroup.section }} · {{ editingGroup.academic_year }}
                                    </p>
                                </div>
                                <button
                                    @click="closeEditModal"
                                    class="p-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <div class="p-4 sm:p-6 space-y-3 max-h-[calc(100vh-180px)] overflow-y-auto">
                                <div v-if="editForm.fee_types.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-xl">
                                    <p class="text-3xl mb-2">📭</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">No fee types. Add one below.</p>
                                </div>

                                <div
                                    v-for="(fee, index) in editForm.fee_types"
                                    :key="index"
                                    class="p-3 rounded-xl bg-white dark:bg-slate-900 border-2 border-slate-200 dark:border-slate-700"
                                    :class="!fee.is_active ? 'opacity-60' : ''"
                                >
                                    <div class="flex items-start gap-2">
                                        <div :class="`w-9 h-9 rounded-lg bg-gradient-to-br ${fee.color} flex items-center justify-center text-base shadow-sm flex-shrink-0`">
                                            {{ fee.icon }}
                                        </div>
                                        <div class="flex-1 grid grid-cols-1 sm:grid-cols-12 gap-2">
                                            <div class="sm:col-span-4">
                                                <input
                                                    v-model="fee.name"
                                                    type="text"
                                                    placeholder="Fee name"
                                                    class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5 text-xs font-medium text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/10"
                                                />
                                            </div>
                                            <div class="sm:col-span-3">
                                                <div class="relative">
                                                    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400 dark:text-slate-500 font-bold">৳</span>
                                                    <input
                                                        v-model.number="fee.amount"
                                                        type="number"
                                                        min="0"
                                                        class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 pl-6 pr-2 py-1.5 text-xs font-bold text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/10"
                                                    />
                                                </div>
                                            </div>
                                            <div class="sm:col-span-3">
                                                <select
                                                    v-model="fee.cycle"
                                                    class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1.5 text-xs font-medium text-slate-800 dark:text-slate-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/10 cursor-pointer"
                                                >
                                                    <option v-for="opt in cycleOptions" :key="opt.value" :value="opt.value">
                                                        {{ opt.label }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2 flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    @click="fee.is_active = !fee.is_active"
                                                    class="flex-1 inline-flex items-center justify-center gap-1 px-2 py-1.5 rounded-lg text-[10px] font-bold transition-all"
                                                    :class="fee.is_active
                                                        ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700'
                                                        : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 border border-slate-300 dark:border-slate-600'"
                                                >
                                                    {{ fee.is_active ? 'On' : 'Off' }}
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="removeEditFeeType(index)"
                                                    class="p-1.5 rounded-lg bg-rose-50 dark:bg-rose-900/40 text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/60 border border-rose-200 dark:border-rose-800 transition-all"
                                                >
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click="addEditFeeType"
                                    class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 text-xs font-semibold text-slate-600 dark:text-slate-400 hover:border-blue-500 hover:text-blue-700 dark:hover:text-blue-300 hover:bg-blue-50/40 dark:hover:bg-blue-950/40 transition-all"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                                    </svg>
                                    Add Another Fee Type
                                </button>
                            </div>

                            <div class="sticky bottom-0 bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-2 transition-colors duration-300">
                                <button
                                    @click="closeEditModal"
                                    class="px-4 py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-all active:scale-95"
                                >
                                    Cancel
                                </button>
                                <button
                                    @click="submitEdit"
                                    :disabled="editForm.processing"
                                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-500 to-indigo-600 text-sm font-bold text-white hover:from-blue-600 hover:to-indigo-700 hover:shadow-lg transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <svg v-if="!editForm.processing" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    {{ editForm.processing ? 'Updating...' : 'Update Fee Structure' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

    </AuthenticatedLayout>
</template>