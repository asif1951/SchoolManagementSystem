<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
    teacherClasses: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    classInfo: { type: Object, default: null },
    students: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    summary: {
        type: Object,
        default: () => ({
            total: 0, present: 0, absent: 0,
            late: 0, leave: 0, percentage: 0,
        }),
    },
    selectedDate: {
        type: String,
        default: () => new Date().toISOString().split('T')[0],
    },
    isAdmin: { type: Boolean, default: false },
    availableYears: { type: Array, default: () => [] },
    // 🆕 Class / Section filter options (from backend)
    availableClassNames: { type: Array, default: () => [] },
    availableSections: { type: Array, default: () => [] },
    hasNoSection: { type: Boolean, default: false },
    selectedYear: { type: String, default: () => String(new Date().getFullYear()) },
    filters: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
})

// ============================================================
// ✅ Year Filter State
// ============================================================
const selectedYear = ref(String(props.selectedYear || new Date().getFullYear()))
const yearInput = ref(selectedYear.value)
const showYearDropdown = ref(false)

const yearSuggestions = computed(() => {
    const years = [...props.availableYears].map(String)
    const currentYear = String(new Date().getFullYear())
    if (!years.includes(currentYear)) years.push(currentYear)
    return years.sort((a, b) => String(b).localeCompare(String(a)))
})

const filteredYearSuggestions = computed(() => {
    if (!yearInput.value) return yearSuggestions.value
    const input = String(yearInput.value).trim()
    return yearSuggestions.value.filter(y => String(y).includes(input))
})

// ============================================================
// 🆕 Class + Section Filter State (Backend)
// ============================================================
const selectedClassName = ref(props.filters?.class_name || '')
const selectedSection = ref(props.filters?.section || '')

const hasActiveFilters = computed(() => {
    return !!(classSearch.value || selectedClassName.value || selectedSection.value)
})

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
    if (year === selectedYear.value) {
        showYearDropdown.value = false
        return
    }
    selectedYear.value = year
    showYearDropdown.value = false
    handleYearChange()
}

const selectYearSuggestion = (year) => {
    yearInput.value = String(year)
    selectedYear.value = String(year)
    showYearDropdown.value = false
    handleYearChange()
}

const incrementYear = () => {
    const current = parseInt(yearInput.value || selectedYear.value)
    yearInput.value = String(current + 1)
    applyYear()
}

const decrementYear = () => {
    const current = parseInt(yearInput.value || selectedYear.value)
    yearInput.value = String(current - 1)
    applyYear()
}

watch(() => props.selectedYear, (newYear) => {
    if (newYear) {
        selectedYear.value = String(newYear)
        yearInput.value = String(newYear)
    }
})

const handleYearChange = () => {
    // 🆕 Year change → class/section options change, so reset them
    selectedClassName.value = ''
    selectedSection.value = ''

    router.get(route('attendance.index'), {
        academic_year: selectedYear.value,
    }, {
        preserveScroll: true,
        preserveState: true,
    })
}

// ============================================================
// ✅ Class Search Filter (Backend)
// ============================================================
const classSearch = ref(props.filters?.search || '')
let searchTimeout = null

watch(classSearch, () => {
    if (searchTimeout) clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        fetchClasses(1)
    }, 500)
})

const fetchClasses = (page = 1) => {
    router.get(route('attendance.index'), {
        academic_year: selectedYear.value,
        class_name: selectedClassName.value || undefined,   // 🆕
        section: selectedSection.value || undefined,        // 🆕
        search: classSearch.value || undefined,
        page,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

// 🆕 Class dropdown changed → reset section, reload from backend
const handleClassNameChange = () => {
    selectedSection.value = ''
    fetchClasses(1)
}

// 🆕 Section dropdown changed → reload from backend
const handleSectionChange = () => {
    fetchClasses(1)
}

const resetClassFilters = () => {
    classSearch.value = ''
    selectedClassName.value = ''   // 🆕
    selectedSection.value = ''     // 🆕
    fetchClasses(1)
}

// ============================================================
// ✅ Student Search Filter (Backend) for Step 2
// ============================================================
const studentSearch = ref(props.filters?.search || '')

let studentSearchTimeout = null

watch(studentSearch, () => {
    if (!props.classInfo) return
    if (studentSearchTimeout) clearTimeout(studentSearchTimeout)
    studentSearchTimeout = setTimeout(() => {
        fetchStudents(1)
    }, 500)
})

const fetchStudents = (page = 1) => {
    if (!props.classInfo) return
    router.get(route('attendance.show', props.classInfo.id), {
        date: date.value,
        search: studentSearch.value || undefined,
        page,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const resetStudentFilters = () => {
    studentSearch.value = ''
    fetchStudents(1)
}

// ============================================================
// ✅ Students list (from paginator)
// ============================================================
const studentsList = computed(() => props.students?.data || [])

// ============================================================
// Attendance State
// ============================================================
const date = ref(props.selectedDate)
const localStudents = ref([])

watch(() => props.students, () => {
    localStudents.value = (props.students?.data || []).map(s => ({ ...s }))
}, { immediate: true, deep: true })

const form = useForm({
    class_id: props.classInfo?.id ?? null,
    date: date.value,
    attendance: [],
})

const statusOptions = [
    { value: 'present', label: 'Present', icon: '✓' },
    { value: 'absent', label: 'Absent', icon: '✕' },
    { value: 'late', label: 'Late', icon: '🕐' },
    { value: 'leave', label: 'Leave', icon: '📝' },
]

const openClass = (classId) => {
    router.visit(route('attendance.show', classId))
}

const backToClasses = () => {
    router.visit(route('attendance.index'), {
        academic_year: selectedYear.value,
    })
}

const updateStatus = (student, status) => {
    student.status = status
}

const markAllPresent = () => {
    localStudents.value.forEach(s => s.status = 'present')
}

const resetAttendance = () => {
    localStudents.value.forEach(s => s.status = 'present')
}

const localSummary = computed(() => {
    const total = localStudents.value.length
    const present = localStudents.value.filter(s => s.status === 'present').length
    const absent = localStudents.value.filter(s => s.status === 'absent').length
    const late = localStudents.value.filter(s => s.status === 'late').length
    const leave = localStudents.value.filter(s => s.status === 'leave').length
    const percentage = total > 0 ? ((present / total) * 100).toFixed(1) : 0
    return { total, present, absent, late, leave, percentage }
})

const saveAttendance = () => {
    form.attendance = localStudents.value.map(s => ({
        student_id: s.id,
        status: s.status,
    }))
    form.date = date.value
    form.class_id = props.classInfo?.id

    form.post(route('attendance.store'), {
        preserveScroll: true,
    })
}

const handleDateChange = () => {
    fetchStudents(1)
}

const getStatusColor = (status) => {
    const colors = {
        present: 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border-green-300 dark:border-green-700',
        absent: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 border-red-300 dark:border-red-700',
        late: 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 border-yellow-300 dark:border-yellow-700',
        leave: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border-blue-300 dark:border-blue-700',
    }
    return colors[status] || 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600'
}

const getAttendanceColor = (percentage) => {
    if (percentage >= 90) return 'text-emerald-600 dark:text-emerald-400'
    if (percentage >= 75) return 'text-green-600 dark:text-green-400'
    if (percentage >= 50) return 'text-yellow-600 dark:text-yellow-400'
    return 'text-red-600 dark:text-red-400'
}

const getAttendanceBarColor = (percentage) => {
    if (percentage >= 90) return 'bg-emerald-500'
    if (percentage >= 75) return 'bg-green-500'
    if (percentage >= 50) return 'bg-yellow-500'
    return 'bg-red-500'
}

// ⭐ NEW helper — get status button styling for the "card" layout
const statusButtonClass = (statusValue, currentStatus) => {
    const isActive = currentStatus === statusValue

    const activeMap = {
        present: 'bg-emerald-500 text-white border-emerald-600 shadow-md shadow-emerald-500/30 scale-105',
        absent:  'bg-rose-500 text-white border-rose-600 shadow-md shadow-rose-500/30 scale-105',
        late:    'bg-amber-500 text-white border-amber-600 shadow-md shadow-amber-500/30 scale-105',
        leave:   'bg-blue-500 text-white border-blue-600 shadow-md shadow-blue-500/30 scale-105',
    }

    const inactiveMap = {
        present: 'bg-white dark:bg-slate-800 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 hover:border-emerald-400',
        absent:  'bg-white dark:bg-slate-800 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800 hover:bg-rose-50 dark:hover:bg-rose-950/40 hover:border-rose-400',
        late:    'bg-white dark:bg-slate-800 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800 hover:bg-amber-50 dark:hover:bg-amber-950/40 hover:border-amber-400',
        leave:   'bg-white dark:bg-slate-800 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:border-blue-400',
    }

    return isActive
        ? activeMap[statusValue] || 'bg-indigo-500 text-white border-indigo-600'
        : inactiveMap[statusValue] || 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700'
}

// ⭐ NEW helper — student card border color by status
const cardBorderClass = (status) => {
    const map = {
        present: 'border-emerald-300 dark:border-emerald-800 bg-emerald-50/30 dark:bg-emerald-950/20',
        absent:  'border-rose-300 dark:border-rose-800 bg-rose-50/30 dark:bg-rose-950/20',
        late:    'border-amber-300 dark:border-amber-800 bg-amber-50/30 dark:bg-amber-950/20',
        leave:   'border-blue-300 dark:border-blue-800 bg-blue-50/30 dark:bg-blue-950/20',
    }
    return map[status] || 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900'
}

// ⭐ NEW helper — status dot color
const statusDotClass = (status) => {
    const map = {
        present: 'bg-emerald-500',
        absent:  'bg-rose-500',
        late:    'bg-amber-500',
        leave:   'bg-blue-500',
    }
    return map[status] || 'bg-slate-400'
}

onBeforeUnmount(() => {
    if (searchTimeout) clearTimeout(searchTimeout)
    if (studentSearchTimeout) clearTimeout(studentSearchTimeout)
})
</script>

<template>
    <Head :title="classInfo ? `Attendance - ${classInfo.class_name}` : 'Attendance'" />

    <AuthenticatedLayout>
        <div class="w-full px-3 py-4 sm:px-4 sm:py-6 md:px-6 lg:px-8 bg-gradient-to-br from-indigo-50/30 via-white to-purple-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 min-h-screen transition-colors duration-300">

            <!-- ═══════════════════════════════════════════════
                 VIEW 1: CLASS LIST (unchanged)
            ═══════════════════════════════════════════════ -->
            <template v-if="!classInfo">
                <div class="mb-4 sm:mb-6">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-pink-400 to-purple-500 rounded-2xl shadow-lg shadow-purple-500/20 flex-shrink-0">
                            <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3 flex-wrap">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-indigo-800 to-purple-800 dark:from-indigo-300 dark:to-purple-300 bg-clip-text text-transparent truncate">
                                    Attendance Management
                                </h1>
                                <span
                                    v-if="isAdmin"
                                    class="inline-flex items-center gap-1.5 px-2 sm:px-3 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-gradient-to-r from-amber-400 to-orange-500 text-white shadow-md"
                                >
                                    <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                    Admin
                                </span>
                            </div>
                            <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400 flex items-center gap-2 mt-1">
                                <span class="inline-block w-1.5 h-1.5 rounded-full bg-pink-500"></span>
                                {{ isAdmin ? 'All classes across the school' : 'Select a class to take attendance' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="flash?.success" class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-xl bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800">
                    <p class="text-sm font-medium text-green-800 dark:text-green-200 break-words">✅ {{ flash.success }}</p>
                </div>
                <div v-if="flash?.error" class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-xl bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800">
                    <p class="text-sm font-medium text-red-800 dark:text-red-200 break-words">❌ {{ flash.error }}</p>
                </div>

                <!-- Year Filter + 🆕 Class & Section Filter -->
                <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/60 dark:border-indigo-800/60 shadow-sm p-4 sm:p-5 transition-colors duration-300">
                    <div class="flex flex-col xl:flex-row items-start xl:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 flex-shrink-0">
                                <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-indigo-800 dark:text-indigo-200">Academic Year</h3>
                                <p class="text-xs text-indigo-500 dark:text-indigo-400">Select year, class & section</p>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row items-stretch md:items-center gap-3 w-full xl:w-auto">
                            <!-- Year control (unchanged) -->
                            <div class="relative flex items-center gap-2 w-full md:w-auto">
                                <button
                                    type="button"
                                    @click="decrementYear"
                                    class="p-2 rounded-lg border-2 border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-400 transition-colors active:scale-95 flex-shrink-0"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                    </svg>
                                </button>

                                <div class="relative flex-1 sm:flex-none" @focusout="setTimeout(() => showYearDropdown = false, 200)">
                                    <input
                                        v-model="yearInput"
                                        @focus="showYearDropdown = true"
                                        @keydown.enter.prevent="applyYear"
                                        @keydown.esc="showYearDropdown = false"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="4"
                                        placeholder="YYYY"
                                        class="w-full sm:w-36 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 bg-indigo-50/30 dark:bg-slate-800/50 pl-4 pr-10 py-2 sm:py-2.5 text-sm font-bold text-indigo-900 dark:text-indigo-100 text-center tracking-wider outline-none cursor-text focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/10"
                                    />

                                    <button
                                        type="button"
                                        @click="showYearDropdown = !showYearDropdown"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-md text-indigo-500 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition-colors"
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
                                        class="absolute top-full left-0 sm:left-auto sm:right-0 mt-2 w-full sm:w-44 bg-white dark:bg-slate-800 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 shadow-xl z-50 overflow-hidden"
                                    >
                                        <div class="max-h-60 overflow-y-auto py-1">
                                            <button
                                                v-for="year in filteredYearSuggestions"
                                                :key="year"
                                                type="button"
                                                @mousedown.prevent="selectYearSuggestion(year)"
                                                class="w-full text-left px-4 py-2 text-sm font-medium transition-colors flex items-center justify-between"
                                                :class="String(year) === String(selectedYear)
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'text-indigo-800 dark:text-indigo-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/50'"
                                            >
                                                <span>{{ year }}</span>
                                                <span
                                                    v-if="String(year) === String(new Date().getFullYear())"
                                                    class="text-[9px] px-1.5 py-0.5 rounded-full font-bold"
                                                    :class="String(year) === String(selectedYear)
                                                        ? 'bg-white/20 text-white'
                                                        : 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300'"
                                                >
                                                    NOW
                                                </span>
                                            </button>
                                        </div>
                                        <div class="border-t border-indigo-100 dark:border-indigo-800 px-3 py-1.5 bg-indigo-50/50 dark:bg-indigo-950/40">
                                            <p class="text-[10px] text-indigo-600 dark:text-indigo-400 font-medium">
                                                💡 Type year & press Enter
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click="incrementYear"
                                    class="p-2 rounded-lg border-2 border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-400 transition-colors active:scale-95 flex-shrink-0"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                    </svg>
                                </button>
                            </div>

                            <!-- 🆕 Class select -->
                            <div class="w-full md:w-44">
                                <label class="block text-[10px] font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-wider mb-1">Class</label>
                                <select
                                    v-model="selectedClassName"
                                    @change="handleClassNameChange"
                                    class="w-full rounded-xl border-2 border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 px-3 py-2 sm:py-2.5 text-sm font-semibold text-indigo-900 dark:text-indigo-100 outline-none cursor-pointer focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                                >
                                    <option value="">All Classes</option>
                                    <option v-for="cn in availableClassNames" :key="cn" :value="cn">{{ cn }}</option>
                                </select>
                            </div>

                            <!-- 🆕 Section select -->
                            <div class="w-full md:w-40">
                                <label class="block text-[10px] font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-wider mb-1">Section</label>
                                <select
                                    v-model="selectedSection"
                                    @change="handleSectionChange"
                                    class="w-full rounded-xl border-2 border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 px-3 py-2 sm:py-2.5 text-sm font-semibold text-indigo-900 dark:text-indigo-100 outline-none cursor-pointer focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                                >
                                    <option value="">All Sections</option>
                                    <option v-for="sec in availableSections" :key="sec" :value="sec">{{ sec }}</option>
                                    <option v-if="hasNoSection" value="__none__">No Section</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Filter -->
                <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/60 dark:border-indigo-800/60 shadow-sm p-3 sm:p-4 transition-colors duration-300">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1 relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-indigo-400 dark:text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="classSearch"
                                type="text"
                                placeholder="Search class, section, or teacher..."
                                class="w-full pl-10 pr-4 py-2 sm:py-2.5 border-2 border-indigo-200 dark:border-indigo-700 rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 bg-white dark:bg-slate-800 text-sm text-indigo-900 dark:text-indigo-100 placeholder:text-slate-400 dark:placeholder:text-slate-500"
                            />
                        </div>
                        <button
                            v-if="hasActiveFilters"
                            @click="resetClassFilters"
                            class="px-4 py-2 sm:py-2.5 text-sm font-medium text-rose-600 dark:text-rose-400 border-2 border-rose-200 dark:border-rose-800 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors flex-shrink-0"
                        >
                            ✕ Clear
                        </button>
                    </div>
                </div>

                <!-- No Classes -->
                <div v-if="!teacherClasses.data || teacherClasses.data.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border border-indigo-200/50 dark:border-indigo-800/60 shadow-sm text-center transition-colors duration-300">
                    <svg class="w-12 h-12 sm:w-16 sm:h-16 text-indigo-300 dark:text-indigo-700 mx-auto mb-3 sm:mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <h3 class="text-base sm:text-lg font-bold text-indigo-800 dark:text-indigo-200 mb-2">No Classes Found</h3>
                    <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400">
                        No classes for year <strong>{{ selectedYear }}</strong>.
                    </p>
                </div>

                <!-- Class Cards -->
                <div v-else>
                    <div class="mb-3 sm:mb-4 flex items-center gap-2 flex-wrap">
                        <h2 class="text-base sm:text-lg font-bold text-indigo-800 dark:text-indigo-200">
                            📚 Classes — {{ selectedYear }}
                        </h2>
                        <span class="text-xs bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 px-2.5 py-1 rounded-full font-semibold">
                            {{ teacherClasses.total }} classes
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                        <button
                            v-for="cls in teacherClasses.data"
                            :key="cls.id"
                            @click="openClass(cls.id)"
                            class="group relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-indigo-200/50 dark:border-indigo-800/60 shadow-sm hover:shadow-xl hover:border-purple-300 dark:hover:border-purple-700 transition-all duration-300 text-left overflow-hidden flex flex-col"
                        >
                            <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br from-pink-400/20 to-purple-500/20 dark:from-pink-600/20 dark:to-purple-700/20 group-hover:scale-150 transition-transform duration-500"></div>

                            <div class="relative flex flex-col flex-1">
                                <!-- Top: Icon + Status badge -->
                                <div class="flex items-start justify-between mb-3">
                                    <div class="p-2.5 rounded-xl bg-gradient-to-br from-pink-400 to-purple-500 shadow-lg shadow-purple-500/20">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                        </svg>
                                    </div>

                                    <span
                                        v-if="cls.today_attendance_taken"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border border-green-300 dark:border-green-700"
                                    >
                                        ✅ Taken
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-700"
                                    >
                                        ⏳ Pending
                                    </span>
                                </div>

                                <!-- Class name + section -->
                                <h3 class="text-lg sm:text-xl font-bold text-indigo-900 dark:text-indigo-100 mb-1 truncate">
                                    {{ cls.class_name }}
                                </h3>
                                <p class="text-sm font-semibold text-purple-600 dark:text-purple-400 mb-2">
                                    Section: {{ cls.section || 'N/A' }}
                                </p>

                                <!-- ⭐ Big student count highlight -->
                                <div class="flex items-center gap-3 mb-3 p-2.5 rounded-xl bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-950/40 dark:to-purple-950/40 border border-indigo-100 dark:border-indigo-800">
                                    <div class="flex items-center justify-center h-10 w-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 shadow-md shadow-indigo-500/20 flex-shrink-0">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-wider leading-none">Students</p>
                                        <p class="text-2xl font-black text-indigo-800 dark:text-indigo-200 leading-tight">
                                            {{ cls.student_count }}
                                            <span class="text-xs font-semibold text-indigo-400 dark:text-indigo-500">enrolled</span>
                                        </p>
                                    </div>
                                </div>

                                <!-- Teacher (admin) -->
                                <p v-if="isAdmin" class="text-xs text-indigo-500 dark:text-indigo-400 mb-3 flex items-center gap-1 font-medium">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    <span class="truncate">Teacher: {{ cls.class_teacher || 'Unassigned' }}</span>
                                </p>

                                <!-- Academic year + Attendance stats -->
                                <div class="mt-auto">
                                    <div class="flex items-center gap-1.5 text-xs text-indigo-500 dark:text-indigo-400 mb-2">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        Academic Year: <span class="font-semibold text-indigo-700 dark:text-indigo-300">{{ cls.academic_year }}</span>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2 pt-3 border-t border-indigo-100 dark:border-indigo-800">
                                        <div class="rounded-lg bg-slate-50 dark:bg-slate-800 px-2.5 py-2 border border-slate-100 dark:border-slate-700">
                                            <p class="text-[10px] font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-wider">Today</p>
                                            <p class="text-base font-black" :class="{
                                                'text-green-600 dark:text-green-400': cls.today_percentage >= 75,
                                                'text-yellow-600 dark:text-yellow-400': cls.today_percentage >= 50 && cls.today_percentage < 75,
                                                'text-red-600 dark:text-red-400': cls.today_percentage < 50,
                                            }">{{ cls.today_percentage }}%</p>
                                        </div>
                                        <div class="rounded-lg bg-slate-50 dark:bg-slate-800 px-2.5 py-2 border border-slate-100 dark:border-slate-700">
                                            <p class="text-[10px] font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-wider">Overall</p>
                                            <p class="text-base font-black" :class="{
                                                'text-green-600 dark:text-green-400': cls.overall_percentage >= 75,
                                                'text-yellow-600 dark:text-yellow-400': cls.overall_percentage >= 50 && cls.overall_percentage < 75,
                                                'text-red-600 dark:text-red-400': cls.overall_percentage < 50,
                                            }">{{ cls.overall_percentage }}%</p>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between pt-3 mt-3 border-t border-indigo-100 dark:border-indigo-800">
                                        <span class="text-xs font-semibold text-purple-600 dark:text-purple-400 group-hover:text-purple-800 dark:group-hover:text-purple-200 transition-colors">
                                            Take Attendance →
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </button>
                    </div>

                    <!-- Class Pagination -->
                    <div v-if="teacherClasses.links && teacherClasses.links.length > 3" class="mt-4 sm:mt-6 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/60 dark:border-indigo-800/60 shadow-sm px-3 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-center justify-between gap-3 transition-colors duration-300">
                        <div class="text-xs sm:text-sm text-indigo-600 dark:text-indigo-400 text-center sm:text-left">
                            Showing <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ teacherClasses.from }}</span> to
                            <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ teacherClasses.to }}</span> of
                            <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ teacherClasses.total }}</span> classes
                        </div>
                        <div class="flex items-center gap-1 flex-wrap justify-center">
                            <template v-for="(link, index) in teacherClasses.links" :key="index">
                                <template v-if="link.url === null">
                                    <span
                                        class="px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-xs sm:text-sm font-medium text-indigo-300 dark:text-indigo-700 bg-indigo-50 dark:bg-indigo-950/40 cursor-not-allowed"
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
                                                ? 'bg-gradient-to-r from-pink-500 to-purple-500 text-white shadow-md shadow-purple-500/25'
                                                : 'bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-400'
                                        ]"
                                        v-html="link.label"
                                    />
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════
                 VIEW 2: STUDENT LIST — REDESIGNED
            ═══════════════════════════════════════════════ -->
            <template v-else>
                <!-- Header with back + title + date picker -->
                <div class="mb-4 sm:mb-6">
                    <button
                        @click="backToClasses"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-200 mb-3 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Classes
                    </button>

                    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-3 sm:gap-4">
                        <div class="flex items-center gap-3 sm:gap-4 w-full lg:w-auto">
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-pink-400 to-purple-500 rounded-2xl shadow-lg shadow-purple-500/20 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-lg sm:text-2xl font-bold bg-gradient-to-r from-indigo-800 to-purple-800 dark:from-indigo-300 dark:to-purple-300 bg-clip-text text-transparent truncate">
                                    {{ classInfo.class_name }} — {{ classInfo.section }}
                                </h1>
                                <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400 mt-0.5">
                                    Year: {{ classInfo.academic_year }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 bg-white dark:bg-slate-800 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 px-3 sm:px-4 py-2 shadow-sm w-full sm:w-auto">
                            <svg class="w-5 h-5 text-indigo-500 dark:text-indigo-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <input
                                v-model="date"
                                @change="handleDateChange"
                                type="date"
                                class="text-sm font-medium text-indigo-800 dark:text-indigo-200 bg-transparent outline-none cursor-pointer w-full"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="flash?.success" class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-xl bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800">
                    <p class="text-sm font-medium text-green-800 dark:text-green-200 break-words">✅ {{ flash.success }}</p>
                </div>

                <!-- Student Search -->
                <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/60 dark:border-indigo-800/60 shadow-sm p-3 sm:p-4 transition-colors duration-300">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1 relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-indigo-400 dark:text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="studentSearch"
                                type="text"
                                placeholder="Search student name, ID or roll..."
                                class="w-full pl-10 pr-4 py-2 sm:py-2.5 border-2 border-indigo-200 dark:border-indigo-700 rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 bg-white dark:bg-slate-800 text-sm text-indigo-900 dark:text-indigo-100 placeholder:text-slate-400 dark:placeholder:text-slate-500"
                            />
                        </div>
                        <button
                            v-if="studentSearch"
                            @click="resetStudentFilters"
                            class="px-4 py-2 sm:py-2.5 text-sm font-medium text-rose-600 dark:text-rose-400 border-2 border-rose-200 dark:border-rose-800 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors flex-shrink-0"
                        >
                            ✕ Clear
                        </button>
                    </div>
                </div>

                <!-- ⭐ Summary cards — redesigned (bigger, clearer) -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-3.5 sm:p-4 border-2 border-indigo-100 dark:border-indigo-800 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-wider">Total</p>
                        <p class="text-2xl sm:text-3xl font-black text-indigo-800 dark:text-indigo-200 leading-none mt-1.5">{{ localSummary.total }}</p>
                    </div>
                    <div class="bg-emerald-50 dark:bg-emerald-950/40 rounded-2xl p-3.5 sm:p-4 border-2 border-emerald-200 dark:border-emerald-800 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Present</p>
                        <p class="text-2xl sm:text-3xl font-black text-emerald-700 dark:text-emerald-300 leading-none mt-1.5">{{ localSummary.present }}</p>
                    </div>
                    <div class="bg-rose-50 dark:bg-rose-950/40 rounded-2xl p-3.5 sm:p-4 border-2 border-rose-200 dark:border-rose-800 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-bold text-rose-600 dark:text-rose-400 uppercase tracking-wider">Absent</p>
                        <p class="text-2xl sm:text-3xl font-black text-rose-700 dark:text-rose-300 leading-none mt-1.5">{{ localSummary.absent }}</p>
                    </div>
                    <div class="bg-amber-50 dark:bg-amber-950/40 rounded-2xl p-3.5 sm:p-4 border-2 border-amber-200 dark:border-amber-800 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Late</p>
                        <p class="text-2xl sm:text-3xl font-black text-amber-700 dark:text-amber-300 leading-none mt-1.5">{{ localSummary.late }}</p>
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-950/40 rounded-2xl p-3.5 sm:p-4 border-2 border-blue-200 dark:border-blue-800 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider">Leave</p>
                        <p class="text-2xl sm:text-3xl font-black text-blue-700 dark:text-blue-300 leading-none mt-1.5">{{ localSummary.leave }}</p>
                    </div>
                    <div class="bg-gradient-to-br from-purple-500 to-indigo-600 rounded-2xl p-3.5 sm:p-4 border-2 border-purple-400 shadow-md col-span-2 sm:col-span-3 lg:col-span-1">
                        <p class="text-[10px] sm:text-xs font-bold text-white/80 uppercase tracking-wider">Attendance</p>
                        <p class="text-2xl sm:text-3xl font-black text-white leading-none mt-1.5">{{ localSummary.percentage }}%</p>
                        <div class="w-full h-1.5 bg-white/30 rounded-full mt-2 overflow-hidden">
                            <div class="h-full bg-white rounded-full transition-all duration-500" :style="{ width: localSummary.percentage + '%' }"></div>
                        </div>
                    </div>
                </div>

                <!-- ⭐ Bulk action toolbar (sticky top) -->
                <div class="mb-5 rounded-2xl bg-white dark:bg-slate-900 border-2 border-indigo-200 dark:border-indigo-800 shadow-sm p-3 sm:p-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 transition-colors duration-300">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-bold text-indigo-800 dark:text-indigo-200">Quick Actions:</span>
                        <button @click="markAllPresent" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 px-3 sm:px-4 py-2 text-xs sm:text-sm font-bold text-white transition shadow-md shadow-emerald-500/25 active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                            Mark All Present
                        </button>
                        <button @click="resetAttendance" class="inline-flex items-center gap-1.5 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 px-3 sm:px-4 py-2 text-xs sm:text-sm font-bold text-indigo-700 dark:text-indigo-300 transition active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            Reset
                        </button>
                    </div>
                    <button
                        @click="saveAttendance"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-pink-500 to-purple-600 hover:from-pink-600 hover:to-purple-700 px-5 sm:px-6 py-2.5 text-sm font-bold text-white shadow-lg shadow-purple-500/30 transition active:scale-95 disabled:opacity-50"
                    >
                        <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                        {{ form.processing ? 'Saving...' : 'Save Attendance' }}
                    </button>
                </div>

                <!-- ⭐ Empty state -->
                <div v-if="studentsList.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border-2 border-indigo-200/60 dark:border-indigo-800/60 shadow-sm text-center transition-colors duration-300">
                    <svg class="w-14 h-14 sm:w-20 sm:h-20 text-indigo-300 dark:text-indigo-700 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <h3 class="text-base sm:text-lg font-bold text-indigo-800 dark:text-indigo-200 mb-1">No Students Found</h3>
                    <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400">This class has no active students.</p>
                </div>

                <!-- ⭐ Student rows — card-based, one card per student -->
                <div v-else class="space-y-3">
                    <div
                        v-for="(student, index) in localStudents"
                        :key="student.id"
                        :class="[
                            'rounded-2xl border-2 shadow-sm overflow-hidden transition-all duration-300',
                            cardBorderClass(student.status)
                        ]"
                    >
                        <div class="flex flex-col lg:flex-row items-stretch lg:items-center gap-3 p-3 sm:p-4">

                            <!-- LEFT: Student info -->
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                <!-- Index badge -->
                                <div class="flex h-8 w-8 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-slate-800 border-2 border-indigo-200 dark:border-indigo-700 text-xs sm:text-sm font-black text-indigo-600 dark:text-indigo-300">
                                    {{ (students.from || 0) + index }}
                                </div>

                                <!-- Avatar -->
                                <div class="relative h-11 w-11 sm:h-14 sm:w-14 shrink-0 rounded-2xl bg-gradient-to-br from-pink-400 to-purple-500 flex items-center justify-center overflow-hidden shadow-md ring-2 ring-white dark:ring-slate-800">
                                    <img
                                        v-if="student.student_photo"
                                        :src="'/storage/' + student.student_photo"
                                        :alt="student.student_name"
                                        class="w-full h-full object-cover"
                                    />
                                    <span v-else class="text-sm sm:text-lg font-black text-white">
                                        {{ student.student_name?.charAt(0)?.toUpperCase() }}
                                    </span>
                                    <!-- Status dot -->
                                    <span
                                        :class="[
                                            'absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 sm:h-4 sm:w-4 rounded-full border-2 border-white dark:border-slate-800 shadow',
                                            statusDotClass(student.status)
                                        ]"
                                    ></span>
                                </div>

                                <!-- Name + ID + Roll -->
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="text-sm sm:text-base font-bold text-indigo-900 dark:text-indigo-100 truncate">
                                            {{ student.student_name }}
                                        </h3>
                                        <span v-if="student.roll_number" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 text-[10px] font-bold">
                                            Roll: {{ student.roll_number }}
                                        </span>
                                    </div>
                                    <p class="text-[11px] sm:text-xs text-indigo-500 dark:text-indigo-400 truncate mt-0.5">
                                        {{ student.student_id }}
                                    </p>

                                    <!-- Attendance mini progress -->
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <span :class="['text-[10px] sm:text-xs font-bold', getAttendanceColor(student.stats?.percentage ?? 0)]">
                                            {{ student.stats?.percentage ?? 0 }}%
                                        </span>
                                        <div class="w-16 sm:w-24 h-1 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                                            <div
                                                :class="['h-full rounded-full transition-all duration-500', getAttendanceBarColor(student.stats?.percentage ?? 0)]"
                                                :style="{ width: (student.stats?.percentage ?? 0) + '%' }"
                                            ></div>
                                        </div>
                                        <span class="text-[10px] sm:text-xs text-indigo-400 dark:text-indigo-500">
                                            ({{ student.stats?.present_days ?? 0 }}/{{ student.stats?.total_days ?? 0 }})
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- RIGHT: Status buttons — LARGE and COLORFUL -->
                            <div class="grid grid-cols-4 gap-2 sm:gap-2.5 w-full lg:w-auto lg:min-w-[360px] shrink-0">
                                <button
                                    v-for="status in statusOptions"
                                    :key="status.value"
                                    type="button"
                                    @click="updateStatus(student, status.value)"
                                    :class="[
                                        'group/btn flex flex-col items-center justify-center gap-0.5 rounded-xl border-2 py-2 sm:py-2.5 px-1 transition-all duration-200 active:scale-95 font-bold',
                                        statusButtonClass(status.value, student.status)
                                    ]"
                                >
                                    <span class="text-base sm:text-lg leading-none">{{ status.icon }}</span>
                                    <span class="text-[9px] sm:text-[10px] font-bold uppercase tracking-wider leading-none">{{ status.label }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Students Pagination -->
                <div v-if="students.links && students.links.length > 3" class="mt-5 bg-white dark:bg-slate-900 rounded-2xl border-2 border-indigo-200/60 dark:border-indigo-800/60 shadow-sm px-3 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-center justify-between gap-3 transition-colors duration-300">
                    <div class="text-xs sm:text-sm text-indigo-600 dark:text-indigo-400 text-center sm:text-left">
                        Showing <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ students.from }}</span> to
                        <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ students.to }}</span> of
                        <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ students.total }}</span> students
                    </div>
                    <div class="flex items-center gap-1 flex-wrap justify-center">
                        <template v-for="(link, index) in students.links" :key="index">
                            <template v-if="link.url === null">
                                <span
                                    class="px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-xs sm:text-sm font-medium text-indigo-300 dark:text-indigo-700 bg-indigo-100 dark:bg-indigo-950/40 cursor-not-allowed"
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
                                            ? 'bg-gradient-to-r from-pink-500 to-purple-500 text-white shadow-md shadow-purple-500/25'
                                            : 'bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-400'
                                    ]"
                                    v-html="link.label"
                                />
                            </template>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>