<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
    classes: { type: Array, default: () => [] },
    classInfo: { type: Object, default: null },
    students: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    totalStudents: { type: Number, default: 0 },
    examTypes: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    availableYears: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
    schoolInfo: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
    // ⭐ Shift feature
    classNames: { type: Array, default: () => [] },
    classSections: { type: Object, default: () => ({}) },
})

// ============================================================
// ✅ Shared page props (school info + logo)
// ============================================================
const page = usePage()
const sharedSchool = computed(() => page.props.school ?? {})

const school = computed(() => ({
    name: sharedSchool.value.name || props.schoolInfo?.name || 'Your School',
    motto: sharedSchool.value.motto || props.schoolInfo?.motto || '',
    logo: sharedSchool.value.logo || props.schoolInfo?.logo || null,
    address: sharedSchool.value.address || props.schoolInfo?.address || '',
    phone: sharedSchool.value.phone || props.schoolInfo?.phone || '',
    email: sharedSchool.value.email || props.schoolInfo?.email || '',
}))

const schoolLogoUrl = computed(() => {
    const logo = school.value.logo
    if (!logo) return null
    if (logo.startsWith('http')) return logo
    return `/storage/${logo.replace(/^\/+/, '')}`
})

// ============================================================
// ⭐ TOAST NOTIFICATION STATE
// ============================================================
const toast = ref({ show: false, message: '', type: 'success' })
let toastTimeout = null

const showToast = (message, type = 'success', duration = 4000) => {
    if (toastTimeout) clearTimeout(toastTimeout)
    toast.value = { show: true, message, type }
    toastTimeout = setTimeout(() => {
        toast.value.show = false
    }, duration)
}

// ============================================================
// Step 1: Year Filter State
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

// ============================================================
// Step 2: Student Filter State
// ============================================================
const selectedExam = ref(props.filters.exam_type || 'final')
const search = ref(props.filters.search || '')
const rollNumber = ref(props.filters.roll_number || '')

let searchTimeout = null

// ============================================================
// Print State
// ============================================================
const printing = ref(false)
const printingStudent = ref(null)

// ============================================================
// ⭐ SHIFT STATE
// ============================================================
const selectedStudentIds = ref([])
const targetClass        = ref('')
const targetSection      = ref('')
const shifting           = ref(false)

// ============================================================
// Students list
// ============================================================
const studentsList = computed(() => props.students?.data || [])

// ⭐ Passed students who are NOT already shifted (selectable)
const passedStudents = computed(() =>
    studentsList.value.filter(s => !s.has_fail && !s.is_shifted)
)

const selectedCount  = computed(() => selectedStudentIds.value.length)
const passedCount    = computed(() => passedStudents.value.length)

// ⭐ Next academic year (From + 1)
const nextYear = computed(() => {
    const from = props.classInfo?.academic_year || props.filters?.academic_year
    return from ? parseInt(from) + 1 : new Date().getFullYear() + 1
})

const isStudentSelected = (id) => selectedStudentIds.value.includes(id)

const toggleStudent = (id) => {
    const idx = selectedStudentIds.value.indexOf(id)
    if (idx === -1) selectedStudentIds.value.push(id)
    else selectedStudentIds.value.splice(idx, 1)
}

const selectAllPassed = () => {
    selectedStudentIds.value = passedStudents.value.map(s => s.id)
}

const clearSelection = () => {
    selectedStudentIds.value = []
}

// Sections for selected target class (from SchoolClass backend)
const availableSections = computed(() => {
    if (!targetClass.value) return []
    const sections = props.classSections?.[targetClass.value] || []
    return Array.isArray(sections) ? sections : []
})

// Reset section if class changes
watch(targetClass, () => {
    targetSection.value = ''
})

// Reset selection when class changes / navigates
watch(() => props.classInfo?.id, () => {
    selectedStudentIds.value = []
    targetClass.value = ''
    targetSection.value = ''
})

// ============================================================
// ⭐ SHIFT ACTION
// ============================================================
const shiftSelected = () => {
    if (!selectedStudentIds.value.length) {
        showToast('Please select at least one student first.', 'error')
        return
    }
    if (!targetClass.value) {
        showToast('Please choose a target class.', 'error')
        return
    }

    const fromYear = props.classInfo?.academic_year || props.filters?.academic_year
    const toYear   = fromYear ? parseInt(fromYear) + 1 : new Date().getFullYear()
    const count    = selectedStudentIds.value.length

    const confirmMsg =
        `Shift ${count} student(s) to ${targetClass.value}${targetSection.value ? ' - ' + targetSection.value : ''} for ${toYear}?\n\n` +
        `• Original ${fromYear} records will stay untouched\n` +
        `• Roll numbers will be preserved\n` +
        `• Duplicate entries will be skipped automatically`

    if (!confirm(confirmMsg)) return

    shifting.value = true

    // ⭐ Processing toast
    showToast(`⏳ Shifting ${count} student(s) to ${targetClass.value} for ${toYear}...`, 'info', 8000)

    router.post(
        route('student.results.shift'),
        {
            student_ids:    selectedStudentIds.value,
            target_class:   targetClass.value,
            target_section: targetSection.value,
            from_year:      fromYear,
            to_year:        toYear,
        },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                const msg = page?.props?.flash?.success
                    || `✅ Successfully shifted ${count} student(s) to ${targetClass.value} for ${toYear}.`
                showToast(msg, 'success', 5000)

                selectedStudentIds.value = []
                targetClass.value = ''
                targetSection.value = ''
            },
            onError: (errors) => {
                const first = Object.values(errors || {})[0]
                    || 'Shift failed. Please try again.'
                showToast('❌ ' + first, 'error', 6000)
            },
            onFinish: () => {
                shifting.value = false
            },
        }
    )
}

// ============================================================
// ⭐ RESET SHIFT (delete shifted record)
// ============================================================
const resetShift = (student) => {
    if (!student?.shifted_record_id) return

    const confirmMsg =
        `Reset shift for "${student.student_name}"?\n\n` +
        `This will DELETE the ${student.shifted_to_year} record:\n` +
        `• Class: ${student.shifted_to_class}\n` +
        `• Section: ${student.shifted_to_section || 'N/A'}\n\n` +
        `After this, you can shift the student again.`

    if (!confirm(confirmMsg)) return

    showToast(`⏳ Resetting shift for ${student.student_name}...`, 'info', 5000)

    router.delete(route('student.results.reset-shift', student.shifted_record_id), {
        preserveScroll: true,
        onSuccess: () => {
            showToast(`✅ Shift record deleted for ${student.student_name}. You can now shift them again.`, 'success', 5000)
        },
        onError: (errors) => {
            const first = Object.values(errors || {})[0]
                || 'Failed to reset shift.'
            showToast('❌ ' + first, 'error', 6000)
        },
    })
}

// ============================================================
// Year Handlers
// ============================================================
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
    if (numYear === selectedYear.value) {
        showYearDropdown.value = false
        return
    }
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

const incrementYear = () => {
    yearInput.value = parseInt(yearInput.value || selectedYear.value) + 1
    applyYear()
}

const decrementYear = () => {
    yearInput.value = parseInt(yearInput.value || selectedYear.value) - 1
    applyYear()
}

watch(() => props.filters.academic_year, (newYear) => {
    if (newYear) {
        selectedYear.value = newYear
        yearInput.value = newYear
    }
})

// ============================================================
// Class Navigation
// ============================================================
const openClass = (classId) => {
    router.visit(route('student.results.show', {
        classId: classId,
        exam_type: selectedExam.value,
    }))
}

const backToClasses = () => {
    router.visit(route('student.results'))
}

// ============================================================
// Filters (Step 1)
// ============================================================
const handleYearChange = () => {
    router.get(route('student.results'), {
        academic_year: selectedYear.value,
    }, {
        preserveScroll: true,
        preserveState: true,
    })
}

// ============================================================
// Filters (Step 2 — Students)
// ============================================================
const applyStudentFilters = () => {
    if (!props.classInfo) return
    router.get(route('student.results.show', props.classInfo.id), {
        exam_type: selectedExam.value,
        search: search.value,
        roll_number: rollNumber.value,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}

watch([search, rollNumber], () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        applyStudentFilters()
    }, 500)
})

onBeforeUnmount(() => {
    clearTimeout(searchTimeout)
    if (toastTimeout) clearTimeout(toastTimeout)
})

const handleExamChange = () => {
    applyStudentFilters()
}

const resetStudentFilters = () => {
    clearTimeout(searchTimeout)
    search.value = ''
    rollNumber.value = ''
    applyStudentFilters()
}

const hasActiveFilters = computed(() => {
    return search.value || rollNumber.value
})

// ============================================================
// Print
// ============================================================
const printResult = (student) => {
    printingStudent.value = student
    printing.value = true
    setTimeout(() => {
        window.print()
        setTimeout(() => {
            printing.value = false
            printingStudent.value = null
        }, 500)
    }, 100)
}

// ============================================================
// Helpers
// ============================================================
const getExamTypeLabel = (type) => {
    const labels = {
        first_terminal: 'First Terminal Examination',
        second_terminal: 'Second Terminal Examination',
        final: 'Final Examination',
        model_test: 'Model Test',
    }
    return labels[type] || type
}

const activeExamLabel = computed(() => getExamTypeLabel(selectedExam.value))

const getGradeBadgeClass = (grade) => {
    if (grade === 'A+') return 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700'
    if (grade === 'A') return 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border-green-300 dark:border-green-700'
    if (grade === 'A-') return 'bg-lime-100 dark:bg-lime-900/40 text-lime-700 dark:text-lime-300 border-lime-300 dark:border-lime-700'
    if (grade === 'B') return 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 border-yellow-300 dark:border-yellow-700'
    if (grade === 'C') return 'bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300 border-orange-300 dark:border-orange-700'
    if (grade === 'D') return 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 border-rose-300 dark:border-rose-700'
    if (grade === 'F') return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 border-red-300 dark:border-red-700'
    return 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-600'
}

const getStudentTotalMarks = (student) => {
    if (student && student.total_marks !== undefined && student.total_marks !== null) {
        return student.total_marks
    }
    let total = 0
    ;(student?.subjects || []).forEach(sub => {
        if (sub.marks !== null && sub.marks !== undefined) {
            total += Number(sub.marks)
        }
    })
    return total
}

const getStudentRank = (student) => {
    return student?.rank ?? null
}

const getOrdinal = (n) => {
    if (n === null || n === undefined || n === 0 || n === '-') return '—'
    const num = Number(n)
    if (isNaN(num)) return '—'
    const s = ['th', 'st', 'nd', 'rd']
    const v = num % 100
    return num + (s[(v - 20) % 10] || s[v] || s[0])
}

// Print helpers
const gradeGpa = (grade) => {
    const g = String(grade || '').toUpperCase()
    const map = { 'A+': '5.00', 'A': '4.00', 'A-': '3.50', 'B': '3.00', 'C': '2.00', 'D': '1.00', 'F': '0.00' }
    return map[g] || '—'
}

const gradeColor = (grade) => {
    const g = String(grade || '').toUpperCase()
    if (g === 'A+') return '#047857'
    if (g === 'A') return '#15803d'
    if (g === 'A-') return '#4d7c0f'
    if (g === 'B') return '#a16207'
    if (g === 'C') return '#c2410c'
    if (g === 'D') return '#be123c'
    if (g === 'F') return '#b91c1c'
    return '#475569'
}

// Student getters for print
const getStudentId = (s) => s?.student_id || s?.id || '—'
const getStudentName = (s) => s?.name || s?.student_name || '—'
const getFatherName = (s) => s?.father_name || s?.fatherName || '—'
const getMotherName = (s) => s?.mother_name || s?.motherName || '—'
const getClassName = (s) => s?.class_name || s?.class || '—'
const getSection = (s) => s?.section || '—'
const getRoll = (s) => s?.roll || s?.roll_number || '—'
const getDob = (s) => s?.dob || s?.date_of_birth || '—'
</script>

<template>
    <Head :title="classInfo ? `Result - ${classInfo.class_name}` : 'Student Results'" />

    <AuthenticatedLayout>
        <component :is="'style'">
            @media print {
                @page {
                    size: A4 portrait;
                    margin: 0.35in;
                }

                * {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }

                html, body {
                    margin: 0 !important;
                    padding: 0 !important;
                    width: 100% !important;
                    height: 100% !important;
                    background: #ffffff !important;
                    overflow: visible !important;
                }

                body * { visibility: hidden !important; }

                footer, nav, aside, .no-print,
                [class*="sidebar"], [class*="navbar"], [class*="footer"], [class*="topbar"], [class*="branding"] {
                    display: none !important;
                    visibility: hidden !important;
                }

                #print-area {
                    display: flex !important;
                    flex-direction: column !important;
                    visibility: visible !important;
                    position: absolute !important;
                    top: 0 !important;
                    left: 0 !important;
                    width: 100% !important;
                    min-height: 100vh !important;
                    background: #ffffff !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    z-index: 999999 !important;
                    transform: none !important;
                }

                #print-area * { visibility: visible !important; }
                #print-area, #print-area * {
                    page-break-inside: avoid !important;
                    break-inside: avoid !important;
                }
            }
        </component>

        <!-- ⭐ GLOBAL TOAST NOTIFICATION -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-3 scale-[0.98]"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100 translate-y-0 scale-100"
            leave-to-class="opacity-0 -translate-y-3 scale-[0.98]"
        >
            <div
                v-if="toast.show"
                class="fixed top-4 left-1/2 -translate-x-1/2 z-[9999] max-w-[90vw] sm:max-w-md w-full px-3 no-print"
            >
                <div
                    :class="[
                        'flex items-start gap-3 rounded-2xl border-2 p-3 sm:p-4 shadow-2xl backdrop-blur-md',
                        toast.type === 'success' ? 'bg-emerald-50/95 dark:bg-emerald-950/90 border-emerald-300 dark:border-emerald-700 text-emerald-900 dark:text-emerald-200' : '',
                        toast.type === 'error'   ? 'bg-rose-50/95 dark:bg-rose-950/90 border-rose-300 dark:border-rose-700 text-rose-900 dark:text-rose-200' : '',
                        toast.type === 'info'    ? 'bg-blue-50/95 dark:bg-blue-950/90 border-blue-300 dark:border-blue-700 text-blue-900 dark:text-blue-200' : '',
                    ]"
                >
                    <span class="text-xl sm:text-2xl flex-shrink-0 leading-none mt-0.5">
                        <template v-if="toast.type === 'success'">✅</template>
                        <template v-else-if="toast.type === 'error'">❌</template>
                        <template v-else-if="toast.type === 'info'">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 animate-spin text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                    </span>
                    <p class="text-xs sm:text-sm font-semibold flex-1 break-words leading-relaxed">
                        {{ toast.message }}
                    </p>
                    <button
                        type="button"
                        @click="toast.show = false"
                        class="flex-shrink-0 rounded-lg p-1 transition text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-700 dark:hover:text-slate-200"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>

        <div class="w-full min-h-screen bg-gradient-to-br from-emerald-50/30 via-white to-teal-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 no-print transition-colors duration-300">
            <div class="mx-auto max-w-[1600px] px-2.5 xs:px-3 sm:px-4 md:px-6 lg:px-8 py-3 xs:py-4 sm:py-6 lg:py-8">

                <!-- VIEW 1: CLASS LIST -->
                <template v-if="!classInfo">
                    <div class="mb-4 sm:mb-6">
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl shadow-lg shadow-emerald-500/25 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-emerald-800 to-teal-800 dark:from-emerald-300 dark:to-teal-300 bg-clip-text text-transparent truncate">
                                    Student Results
                                </h1>
                                <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 flex items-center gap-2 mt-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    Select a class to view all students results
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

                    <!-- Year Filter -->
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

                                <div class="relative flex-1 sm:flex-none" @focusout="setTimeout(() => showYearDropdown = false, 200)">
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
                                        class="w-full sm:w-36 rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 pl-4 pr-10 py-2 sm:py-2.5 text-sm font-bold text-emerald-900 dark:text-emerald-100 text-center tracking-wider outline-none cursor-text focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10"
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
                                        class="absolute top-full left-0 sm:left-auto sm:right-0 mt-2 w-full sm:w-44 bg-white dark:bg-slate-900 rounded-xl border-2 border-emerald-200 dark:border-emerald-800 shadow-xl z-50 overflow-hidden"
                                    >
                                        <div class="max-h-60 overflow-y-auto py-1">
                                            <button
                                                v-for="year in filteredYearSuggestions"
                                                :key="year"
                                                type="button"
                                                @mousedown.prevent="selectYearSuggestion(year)"
                                                class="w-full text-left px-4 py-2 text-sm font-medium transition-colors flex items-center justify-between"
                                                :class="year === selectedYear
                                                    ? 'bg-emerald-600 text-white'
                                                    : 'text-emerald-800 dark:text-emerald-200 hover:bg-emerald-50 dark:hover:bg-emerald-950/50'"
                                            >
                                                <span>{{ year }}</span>
                                                <span
                                                    v-if="year === new Date().getFullYear()"
                                                    class="text-[9px] px-1.5 py-0.5 rounded-full font-bold"
                                                    :class="year === selectedYear
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
                    </div>

                    <!-- No Classes -->
                    <div v-if="classes.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm text-center transition-colors duration-300">
                        <svg class="w-12 h-12 sm:w-16 sm:h-16 text-emerald-300 dark:text-emerald-700 mx-auto mb-3 sm:mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                        <h3 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200 mb-2">No Classes Found</h3>
                        <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400">
                            No classes for year <strong>{{ selectedYear }}</strong>.
                        </p>
                    </div>

                    <!-- Class Cards -->
                    <div v-else>
                        <div class="mb-4 flex items-center gap-2 flex-wrap">
                            <h2 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200">
                                📚 Classes for {{ selectedYear }}
                            </h2>
                            <span class="text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300 px-2.5 py-1 rounded-full font-semibold">
                                {{ classes.length }} classes
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                            <button
                                v-for="cls in classes"
                                :key="cls.id"
                                @click="openClass(cls.id)"
                                class="group relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-6 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm hover:shadow-xl hover:border-emerald-400 dark:hover:border-emerald-600 transition-all duration-300 text-left overflow-hidden"
                            >
                                <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br from-emerald-400/20 to-teal-500/20 dark:from-emerald-600/20 dark:to-teal-700/20 group-hover:scale-150 transition-transform duration-500"></div>

                                <div class="relative">
                                    <div class="flex items-start justify-between mb-3 sm:mb-4">
                                        <div class="p-2.5 sm:p-3 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/20">
                                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                            </svg>
                                        </div>

                                        <span class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">
                                            {{ cls.subjects?.length || 0 }} subjects
                                        </span>
                                    </div>

                                    <h3 class="text-lg sm:text-xl font-bold text-emerald-900 dark:text-emerald-100 mb-1 truncate">
                                        {{ cls.class_name }}
                                    </h3>
                                    <p class="text-sm font-semibold text-teal-600 dark:text-teal-400 mb-1">
                                        Section: {{ cls.section || 'N/A' }}
                                    </p>

                                    <p v-if="isAdmin" class="text-xs text-emerald-500 dark:text-emerald-400 mb-3 flex items-center gap-1 font-medium">
                                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                        <span class="truncate">Teacher: {{ cls.class_teacher || 'Unassigned' }}</span>
                                    </p>
                                    <p v-else class="mb-3"></p>

                                    <div v-if="cls.subjects?.length" class="flex flex-wrap gap-1 mb-3 sm:mb-4">
                                        <span
                                            v-for="subject in cls.subjects.slice(0, 3)"
                                            :key="subject"
                                            class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800"
                                        >
                                            {{ subject }}
                                        </span>
                                        <span
                                            v-if="cls.subjects.length > 3"
                                            class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-teal-50 dark:bg-teal-900/40 text-teal-600 dark:text-teal-300 border border-teal-100 dark:border-teal-800"
                                        >
                                            +{{ cls.subjects.length - 3 }} more
                                        </span>
                                    </div>

                                    <div class="flex items-center justify-between pt-3 sm:pt-4 border-t border-emerald-100 dark:border-emerald-800">
                                        <span class="text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0z" />
                                            </svg>
                                            {{ cls.student_count }} students
                                        </span>
                                        <span class="text-xs font-semibold text-teal-600 dark:text-teal-400 group-hover:text-teal-800 dark:group-hover:text-teal-200 transition-colors">
                                            View Results →
                                        </span>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                </template>

                <!-- VIEW 2: STUDENTS RESULT LIST -->
                <template v-else>
                    <div class="mb-4 sm:mb-6">
                        <button
                            @click="backToClasses"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-200 mb-3 transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Back to Classes
                        </button>

                        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-3 sm:gap-4">
                            <div class="flex items-center gap-3 sm:gap-4 w-full lg:w-auto">
                                <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl shadow-lg shadow-emerald-500/25 flex-shrink-0">
                                    <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                    </svg>
                                </div>
                                <div class="min-w-0">
                                    <h1 class="text-lg sm:text-2xl font-bold bg-gradient-to-r from-emerald-800 to-teal-800 dark:from-emerald-300 dark:to-teal-300 bg-clip-text text-transparent truncate">
                                        {{ classInfo.class_name }} — {{ classInfo.section }}
                                    </h1>
                                    <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 mt-0.5">
                                        Academic Year: {{ classInfo.academic_year }}
                                        <span class="mx-1">·</span>
                                        <span class="font-semibold">{{ students.total || 0 }}</span> of {{ totalStudents }} students
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Flash messages (view 2) -->
                    <div v-if="flash?.success" class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-xl bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800">
                        <p class="text-sm font-medium text-green-800 dark:text-green-200 break-words">✅ {{ flash.success }}</p>
                    </div>
                    <div v-if="flash?.error" class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-xl bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800">
                        <p class="text-sm font-medium text-red-800 dark:text-red-200 break-words">❌ {{ flash.error }}</p>
                    </div>

                    <!-- ⭐ SHIFT TOOLBAR -->
                    <div class="mb-4 sm:mb-5 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-950/40 dark:to-purple-950/40 rounded-2xl border-2 border-indigo-200 dark:border-indigo-800 shadow-sm p-3 sm:p-4 transition-colors duration-300">
                        <div class="flex flex-col lg:flex-row items-stretch lg:items-center gap-3">

                            <div class="flex items-center gap-2 bg-white dark:bg-slate-800 rounded-xl border-2 border-indigo-200 dark:border-indigo-800 px-3 py-2 shadow-sm flex-shrink-0">
                                <div class="text-center">
                                    <p class="text-[9px] font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-wider">From</p>
                                    <p class="text-sm sm:text-base font-extrabold text-indigo-700 dark:text-indigo-300 leading-none">
                                        {{ props.classInfo?.academic_year || props.filters?.academic_year || '—' }}
                                    </p>
                                </div>

                                <svg class="w-5 h-5 text-indigo-400 dark:text-indigo-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>

                                <div class="text-center">
                                    <p class="text-[9px] font-bold text-emerald-500 dark:text-emerald-400 uppercase tracking-wider">To</p>
                                    <p class="text-sm sm:text-base font-extrabold text-emerald-600 dark:text-emerald-400 leading-none">
                                        {{ nextYear }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <button
                                    type="button"
                                    @click="selectAllPassed"
                                    :disabled="passedCount === 0"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 px-3 sm:px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-md shadow-emerald-500/25 transition-all hover:from-emerald-600 hover:to-teal-700 active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed"
                                >
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Select All Passed ({{ passedCount }})
                                </button>

                                <button
                                    v-if="selectedCount > 0"
                                    type="button"
                                    @click="clearSelection"
                                    class="inline-flex items-center gap-1.5 rounded-xl border-2 border-rose-300 dark:border-rose-800 bg-white dark:bg-slate-800 px-3 sm:px-4 py-2 text-xs sm:text-sm font-bold text-rose-600 dark:text-rose-400 shadow-sm transition-all hover:bg-rose-50 dark:hover:bg-rose-950/50 active:scale-95"
                                >
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    Clear
                                </button>

                                <span v-if="selectedCount > 0" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 text-xs font-bold">
                                    {{ selectedCount }} selected
                                </span>
                            </div>

                            <div class="flex-1"></div>

                            <div class="flex items-stretch gap-2 flex-wrap">
                                <select
                                    v-model="targetClass"
                                    :disabled="selectedCount === 0"
                                    class="flex-1 lg:flex-none lg:w-44 rounded-xl border-2 border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 px-3 py-2 text-xs sm:text-sm font-semibold text-indigo-900 dark:text-indigo-100 outline-none cursor-pointer focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 disabled:opacity-50"
                                >
                                    <option value="">-- Target class --</option>
                                    <option v-for="c in classNames" :key="c" :value="c">{{ c }}</option>
                                </select>

                                <select
                                    v-model="targetSection"
                                    :disabled="selectedCount === 0 || !targetClass"
                                    class="flex-1 lg:flex-none lg:w-32 rounded-xl border-2 border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 px-3 py-2 text-xs sm:text-sm font-semibold text-indigo-900 dark:text-indigo-100 outline-none cursor-pointer focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 disabled:opacity-50"
                                >
                                    <option value="">Section</option>
                                    <option v-for="s in availableSections" :key="s" :value="s">{{ s }}</option>
                                </select>

                                <button
                                    type="button"
                                    @click="shiftSelected"
                                    :disabled="selectedCount === 0 || !targetClass || shifting"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 px-4 sm:px-5 py-2 text-xs sm:text-sm font-bold text-white shadow-md shadow-indigo-500/25 transition-all hover:from-indigo-700 hover:to-purple-700 active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap"
                                >
                                    <svg v-if="shifting" class="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <svg v-else class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                    </svg>
                                    {{ shifting ? 'Shifting...' : `Shift ${selectedCount || ''}` }}
                                </button>
                            </div>
                        </div>

                        <p class="mt-2 text-[11px] sm:text-xs text-indigo-600 dark:text-indigo-400 font-medium">
                            💡 Shifting from <strong>{{ props.classInfo?.academic_year || props.filters?.academic_year }}</strong> to <strong>{{ nextYear }}</strong>. Original records stay untouched. Roll numbers are preserved.
                        </p>
                    </div>

                    <!-- FILTER SECTION -->
                    <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm p-4 sm:p-5 transition-colors duration-300">
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-12 gap-3 sm:gap-4">
                            <div class="md:col-span-5">
                                <label class="block text-xs font-bold text-emerald-700 dark:text-emerald-300 uppercase tracking-wider mb-1.5 sm:mb-2">
                                    🔍 Search Name / ID
                                </label>
                                <div class="relative">
                                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-emerald-400 dark:text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    <input
                                        v-model="search"
                                        type="text"
                                        placeholder="Type student name or ID..."
                                        class="w-full rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 pl-10 pr-4 py-2 sm:py-2.5 text-sm text-emerald-900 dark:text-emerald-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition-all focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10"
                                    />
                                </div>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs font-bold text-emerald-700 dark:text-emerald-300 uppercase tracking-wider mb-1.5 sm:mb-2">
                                    🎫 Roll Number
                                </label>
                                <input
                                    v-model="rollNumber"
                                    type="text"
                                    placeholder="Search by roll..."
                                    class="w-full rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 px-4 py-2 sm:py-2.5 text-sm text-emerald-900 dark:text-emerald-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition-all focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10"
                                />
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs font-bold text-emerald-700 dark:text-emerald-300 uppercase tracking-wider mb-1.5 sm:mb-2">
                                    📝 Exam Type
                                </label>
                                <select
                                    v-model="selectedExam"
                                    @change="handleExamChange"
                                    class="w-full rounded-xl border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-slate-800/50 px-4 py-2 sm:py-2.5 text-sm font-medium text-emerald-900 dark:text-emerald-100 outline-none cursor-pointer focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10"
                                >
                                    <option v-for="et in examTypes" :key="et.value" :value="et.value">
                                        {{ et.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="md:col-span-1 flex items-end">
                                <button
                                    type="button"
                                    @click="resetStudentFilters"
                                    :disabled="!hasActiveFilters"
                                    class="w-full p-2 sm:p-2.5 rounded-xl border-2 border-rose-200 dark:border-rose-800 bg-white dark:bg-slate-800 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/50 hover:border-rose-400 transition-colors active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed"
                                    title="Reset filters"
                                >
                                    <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div v-if="hasActiveFilters" class="mt-3 sm:mt-4 flex flex-wrap items-center gap-2 pt-3 border-t border-emerald-100 dark:border-emerald-800">
                            <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">Active filters:</span>
                            <span v-if="search" class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs font-medium">
                                Name: "{{ search }}"
                                <button @click="search = ''; applyStudentFilters()" class="hover:text-emerald-900 dark:hover:text-emerald-100">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                            <span v-if="rollNumber" class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs font-medium">
                                Roll: "{{ rollNumber }}"
                                <button @click="rollNumber = ''; applyStudentFilters()" class="hover:text-emerald-900 dark:hover:text-emerald-100">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-4 sm:mb-6">
                        <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-emerald-200/50 dark:border-emerald-800/60 shadow-sm transition-colors duration-300">
                            <p class="text-[10px] sm:text-xs font-medium text-emerald-400 dark:text-emerald-500 uppercase tracking-wider">Showing</p>
                            <p class="text-xl sm:text-2xl font-bold text-emerald-800 dark:text-emerald-200">
                                {{ students.total || 0 }}
                                <span class="text-xs sm:text-sm font-medium text-emerald-400 dark:text-emerald-500">/ {{ totalStudents }}</span>
                            </p>
                        </div>
                        <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-green-200/50 dark:border-green-800/60 shadow-sm transition-colors duration-300">
                            <p class="text-[10px] sm:text-xs font-medium text-green-500 dark:text-green-400 uppercase tracking-wider">Passed</p>
                            <p class="text-xl sm:text-2xl font-bold text-green-600 dark:text-green-400">
                                {{ passedCount }}
                            </p>
                        </div>
                        <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-rose-200/50 dark:border-rose-800/60 shadow-sm transition-colors duration-300">
                            <p class="text-[10px] sm:text-xs font-medium text-rose-500 dark:text-rose-400 uppercase tracking-wider">Failed</p>
                            <p class="text-xl sm:text-2xl font-bold text-rose-600 dark:text-rose-400">
                                {{ studentsList.filter(s => s.has_fail).length }}
                            </p>
                        </div>
                        <div class="bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-950/40 dark:to-teal-950/40 rounded-xl p-3 sm:p-4 border border-emerald-200/50 dark:border-emerald-800/60 shadow-sm transition-colors duration-300">
                            <p class="text-[10px] sm:text-xs font-medium text-emerald-400 dark:text-emerald-500 uppercase tracking-wider">Exam</p>
                            <p class="text-xs sm:text-sm font-bold text-emerald-700 dark:text-emerald-300 mt-1">{{ activeExamLabel }}</p>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div v-if="studentsList.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm text-center transition-colors duration-300">
                        <svg class="w-12 h-12 sm:w-16 sm:h-16 text-emerald-300 dark:text-emerald-700 mx-auto mb-3 sm:mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <h3 class="text-base sm:text-lg font-bold text-emerald-800 dark:text-emerald-200 mb-2">
                            {{ hasActiveFilters ? 'No Matching Students' : 'No Students Found' }}
                        </h3>
                        <p class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 mb-4">
                            {{ hasActiveFilters
                                ? 'Try changing your search or filter criteria.'
                                : 'This class has no active students.' }}
                        </p>
                        <button
                            v-if="hasActiveFilters"
                            @click="resetStudentFilters"
                            class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 px-4 sm:px-5 py-2 sm:py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/25 transition-all hover:from-emerald-600 hover:to-teal-700 active:scale-95"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            Reset Filters
                        </button>
                    </div>

                    <!-- Students List -->
                    <div v-else class="space-y-3 sm:space-y-4">
                        <div
                            v-for="student in studentsList"
                            :key="student.id"
                            :class="[
                                'bg-white dark:bg-slate-900 rounded-2xl border-2 shadow-sm overflow-hidden transition-all',
                                isStudentSelected(student.id)
                                    ? 'border-indigo-400 dark:border-indigo-600 ring-4 ring-indigo-100 dark:ring-indigo-900/40 shadow-md'
                                    : student.is_shifted
                                        ? 'border-amber-300 dark:border-amber-700 bg-amber-50/20 dark:bg-amber-950/20'
                                        : 'border-emerald-200/60 dark:border-emerald-800/60 hover:shadow-md'
                            ]"
                        >
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3 sm:p-4 border-b border-emerald-100 dark:border-emerald-800 bg-gradient-to-r from-emerald-50/50 to-teal-50/50 dark:from-emerald-950/30 dark:to-teal-950/30">
                                <div class="flex items-center gap-3 min-w-0 w-full sm:w-auto">

                                    <!-- ⭐ Checkbox (disabled if failed OR already shifted) -->
                                    <label
                                        class="flex items-center gap-2 cursor-pointer select-none flex-shrink-0"
                                        :title="
                                            student.has_fail
                                                ? 'Failed — cannot shift'
                                                : student.is_shifted
                                                    ? `Already shifted to ${student.shifted_to_class} (${student.shifted_to_year})`
                                                    : 'Select to shift'
                                        "
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="isStudentSelected(student.id)"
                                            :disabled="student.has_fail || student.is_shifted"
                                            @change="toggleStudent(student.id)"
                                            class="w-5 h-5 rounded-md border-2 border-emerald-300 dark:border-emerald-700 text-emerald-600 cursor-pointer focus:ring-2 focus:ring-emerald-500 disabled:opacity-30 disabled:cursor-not-allowed"
                                        />
                                    </label>

                                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center overflow-hidden flex-shrink-0 ring-2 ring-white dark:ring-slate-800 shadow-sm">
                                        <img
                                            v-if="student.student_photo"
                                            :src="'/storage/' + student.student_photo"
                                            :alt="student.student_name"
                                            class="w-full h-full object-cover"
                                        />
                                        <span v-else class="text-xs sm:text-sm font-bold text-white">
                                            {{ student.student_name?.charAt(0)?.toUpperCase() }}
                                        </span>
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-sm sm:text-base font-bold text-emerald-900 dark:text-emerald-100 truncate">{{ student.student_name }}</h3>
                                        <p class="text-xs text-emerald-600 dark:text-emerald-400 truncate">
                                            {{ student.class }} - {{ student.section }}
                                            <span v-if="student.roll_number"> · Roll {{ student.roll_number }}</span>
                                            <span class="text-emerald-400 dark:text-emerald-500"> · {{ student.student_id }}</span>
                                        </p>

                                        <!-- ⭐ Already shifted badge -->
                                        <div
                                            v-if="student.is_shifted"
                                            class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-700 text-[10px] font-bold"
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                            </svg>
                                            Shifted to {{ student.shifted_to_class }} ({{ student.shifted_to_year }})
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1.5 sm:gap-2 flex-wrap w-full sm:w-auto">
                                    <div
                                        :class="[
                                            'px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg border-2 text-center',
                                            student.has_fail
                                                ? 'bg-red-50 dark:bg-red-950/40 border-red-300 dark:border-red-800'
                                                : 'bg-green-50 dark:bg-green-950/40 border-green-300 dark:border-green-800'
                                        ]"
                                    >
                                        <p class="text-[8px] sm:text-[9px] font-bold uppercase tracking-wider"
                                           :class="student.has_fail ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                                            Result
                                        </p>
                                        <p class="text-[10px] sm:text-xs font-bold"
                                           :class="student.has_fail ? 'text-red-700 dark:text-red-300' : 'text-green-700 dark:text-green-300'">
                                            {{ student.has_fail ? 'FAILED' : 'PASSED' }}
                                        </p>
                                    </div>

                                    <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-cyan-100 to-teal-100 dark:from-cyan-950/40 dark:to-teal-950/40 border-2 border-cyan-300 dark:border-cyan-800 text-center">
                                        <p class="text-[8px] sm:text-[9px] font-bold text-cyan-700 dark:text-cyan-400 uppercase tracking-wider">Total</p>
                                        <p class="text-[10px] sm:text-xs font-bold text-cyan-900 dark:text-cyan-200">{{ getStudentTotalMarks(student) }}</p>
                                    </div>

                                    <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-amber-100 to-orange-100 dark:from-amber-950/40 dark:to-orange-950/40 border-2 border-amber-300 dark:border-amber-800 text-center">
                                        <p class="text-[8px] sm:text-[9px] font-bold text-amber-700 dark:text-amber-400 uppercase tracking-wider">Position</p>
                                        <p class="text-[10px] sm:text-xs font-bold text-amber-900 dark:text-amber-200">{{ getOrdinal(getStudentRank(student)) }}</p>
                                    </div>

                                    <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-emerald-100 to-teal-100 dark:from-emerald-950/40 dark:to-teal-950/40 border-2 border-emerald-300 dark:border-emerald-800 text-center">
                                        <p class="text-[8px] sm:text-[9px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">GPA</p>
                                        <p class="text-[10px] sm:text-xs font-bold text-emerald-900 dark:text-emerald-200">{{ student.overall_gpa }}</p>
                                    </div>

                                    <div class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg bg-gradient-to-br from-emerald-100 to-teal-100 dark:from-emerald-950/40 dark:to-teal-950/40 border-2 border-emerald-300 dark:border-emerald-800 text-center">
                                        <p class="text-[8px] sm:text-[9px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Grade</p>
                                        <p class="text-[10px] sm:text-xs font-bold"
                                           :class="student.has_fail ? 'text-red-700 dark:text-red-300' : 'text-emerald-900 dark:text-emerald-200'">
                                            {{ student.overall_letter }}
                                        </p>
                                    </div>

                                    <!-- ⭐ Reset Shift button (only if shifted) -->
                                    <button
                                        v-if="student.is_shifted"
                                        @click="resetShift(student)"
                                        class="inline-flex items-center gap-1 sm:gap-1.5 rounded-lg border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-2 sm:px-3 py-1.5 sm:py-2 text-[10px] sm:text-xs font-bold text-amber-700 dark:text-amber-300 shadow-sm transition-all hover:bg-amber-100 dark:hover:bg-amber-900/40 hover:border-amber-400 active:scale-95"
                                        :title="`Remove shift record for ${student.student_name} (${student.shifted_to_year})`"
                                    >
                                        <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        Reset
                                    </button>

                                    <button
                                        @click="printResult(student)"
                                        class="inline-flex items-center gap-1 sm:gap-1.5 rounded-lg bg-gradient-to-r from-emerald-500 to-teal-600 px-2 sm:px-3 py-1.5 sm:py-2 text-[10px] sm:text-xs font-semibold text-white shadow-sm transition-all hover:from-emerald-600 hover:to-teal-700 active:scale-95"
                                    >
                                        <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                        </svg>
                                        Print
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse text-xs sm:text-sm min-w-[500px]">
                                    <thead>
                                        <tr class="bg-emerald-800 dark:bg-emerald-900 text-white">
                                            <th class="border border-emerald-800 dark:border-emerald-900 px-2 sm:px-3 py-1.5 sm:py-2 text-left text-[9px] sm:text-[10px] font-bold uppercase tracking-wider">Subject</th>
                                            <th class="border border-emerald-800 dark:border-emerald-900 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-[9px] sm:text-[10px] font-bold uppercase tracking-wider">Total</th>
                                            <th class="border border-emerald-800 dark:border-emerald-900 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-[9px] sm:text-[10px] font-bold uppercase tracking-wider">Obtained</th>
                                            <th class="border border-emerald-800 dark:border-emerald-900 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-[9px] sm:text-[10px] font-bold uppercase tracking-wider">Highest</th>
                                            <th class="border border-emerald-800 dark:border-emerald-900 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-[9px] sm:text-[10px] font-bold uppercase tracking-wider">LG</th>
                                            <th class="border border-emerald-800 dark:border-emerald-900 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-[9px] sm:text-[10px] font-bold uppercase tracking-wider">GPA</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(sub, idx) in student.subjects"
                                            :key="sub.subject"
                                            :class="idx % 2 === 0 ? 'bg-white dark:bg-slate-900' : 'bg-emerald-50/30 dark:bg-emerald-950/20'"
                                        >
                                            <td class="border border-emerald-200 dark:border-emerald-800 px-2 sm:px-3 py-1.5 sm:py-2 text-xs font-semibold text-slate-800 dark:text-slate-100">{{ sub.subject }}</td>
                                            <td class="border border-emerald-200 dark:border-emerald-800 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-xs text-slate-600 dark:text-slate-400">100</td>
                                            <td class="border border-emerald-200 dark:border-emerald-800 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-xs font-bold text-slate-800 dark:text-slate-100">
                                                {{ sub.marks ?? '—' }}
                                            </td>
                                            <td class="border border-emerald-200 dark:border-emerald-800 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-xs text-slate-600 dark:text-slate-400">
                                                {{ sub.highest ?? '—' }}
                                            </td>
                                            <td class="border border-emerald-200 dark:border-emerald-800 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-xs">
                                                <span :class="['inline-block px-1.5 sm:px-2 py-0.5 rounded-md border text-[9px] sm:text-[10px] font-bold', getGradeBadgeClass(sub.grade)]">
                                                    {{ sub.grade }}
                                                </span>
                                            </td>
                                            <td class="border border-emerald-200 dark:border-emerald-800 px-2 sm:px-3 py-1.5 sm:py-2 text-center text-xs font-bold"
                                                :class="sub.grade === 'F' ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-slate-100'">
                                                {{ sub.gpa !== null ? sub.gpa.toFixed(2) : '—' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div v-if="students?.links && students.links.length > 3" class="mt-4 sm:mt-6 bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/60 shadow-sm px-3 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-center justify-between gap-3 transition-colors duration-300">
                            <div class="text-xs sm:text-sm text-emerald-600 dark:text-emerald-400 text-center sm:text-left">
                                Showing <span class="font-bold text-emerald-800 dark:text-emerald-200">{{ students.from ?? 0 }}</span> to
                                <span class="font-bold text-emerald-800 dark:text-emerald-200">{{ students.to ?? 0 }}</span> of
                                <span class="font-bold text-emerald-800 dark:text-emerald-200">{{ students.total ?? 0 }}</span> students
                            </div>

                            <div class="flex items-center gap-1 flex-wrap justify-center">
                                <template v-for="(link, index) in students.links" :key="index">
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
            </div>
        </div>

        <!-- ==================== PRINTABLE AREA (Redesigned) ==================== -->
        <div
            v-if="printing && printingStudent"
            id="print-area"
            style="
                font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
                position: relative;
                background: #ffffff;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                color-adjust: exact;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            "
        >
            <!-- WATERMARK -->
            <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 0; overflow: hidden;">
                <div style="transform: rotate(-30deg); text-align: center; opacity: 0.07;">
                    <div style="font-size: 100px; font-weight: 900; color: #047857; letter-spacing: 0.08em; line-height: 1; white-space: nowrap;">
                        {{ school.name?.toUpperCase() }}
                    </div>
                    <div style="font-size: 28px; font-weight: 800; color: #047857; letter-spacing: 0.5em; margin-top: 14px;">
                        OFFICIAL
                    </div>
                </div>
            </div>

            <!-- MAIN CONTENT -->
            <div style="padding: 16px 24px; max-width: 100%; position: relative; z-index: 1; display: flex; flex-direction: column; flex: 1;">

                <!-- HEADER -->
                <div style="
                    display: flex; align-items: stretch;
                    background: linear-gradient(135deg, #047857 0%, #0f766e 100%);
                    border-radius: 14px 14px 0 0;
                    padding: 14px 20px;
                    color: #ffffff;
                    box-shadow: 0 6px 18px rgba(4, 120, 87, 0.22);
                    gap: 16px;
                    margin-bottom: 0;
                ">
                    <div style="
                        width: 82px; height: 82px; border-radius: 50%;
                        background: rgba(255, 255, 255, 0.15);
                        border: 3px solid rgba(255, 255, 255, 0.55);
                        display: flex; align-items: center; justify-content: center;
                        flex-shrink: 0; overflow: hidden; padding: 5px;
                    ">
                        <img
                            v-if="schoolLogoUrl"
                            :src="schoolLogoUrl"
                            :alt="school.name"
                            style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;"
                        />
                        <svg v-else style="width: 44px; height: 44px;" fill="none" stroke="#ffffff" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>

                    <div style="flex: 1; text-align: center; display: flex; flex-direction: column; justify-content: center; min-width: 0;">
                        <h1 style="font-size: 26px; font-weight: 900; color: #ffffff; text-transform: uppercase; letter-spacing: 0.05em; margin: 0; line-height: 1.1; text-shadow: 0 1px 3px rgba(0,0,0,0.15);">
                            {{ school.name }}
                        </h1>
                        <p v-if="school.motto" style="font-size: 10px; color: rgba(255,255,255,0.85); margin: 3px 0 0; font-style: italic;">
                            {{ school.motto }}
                        </p>
                        <p v-if="school.address" style="font-size: 10px; color: rgba(255,255,255,0.9); margin: 2px 0 0;">
                            {{ school.address }}
                        </p>
                        <p v-if="school.phone" style="font-size: 9px; color: rgba(255,255,255,0.85); margin: 2px 0 0;">
                            Phone: {{ school.phone }}
                        </p>
                        <div style="margin-top: 8px; padding-top: 6px; border-top: 1px solid rgba(255,255,255,0.3);">
                            <p style="font-size: 12px; font-weight: 800; color: #ffffff; text-transform: uppercase; letter-spacing: 0.3em; margin: 0;">
                                Academic Transcript
                            </p>
                            <p style="font-size: 9px; color: rgba(255,255,255,0.9); letter-spacing: 0.2em; margin: 3px 0 0; font-weight: 700;">
                                {{ activeExamLabel.toUpperCase() }}
                            </p>
                        </div>
                    </div>

                    <div style="
                        width: 100px;
                        background: rgba(255, 255, 255, 0.14);
                        border: 2px solid rgba(255, 255, 255, 0.4);
                        border-radius: 12px;
                        padding: 8px 10px;
                        display: flex; flex-direction: column; align-items: center; justify-content: center;
                        text-align: center; flex-shrink: 0;
                    ">
                        <p style="font-size: 9px; font-weight: 800; color: rgba(255,255,255,0.9); letter-spacing: 0.15em; margin: 0; text-transform: uppercase;">Session</p>
                        <p style="font-size: 24px; font-weight: 900; color: #ffffff; margin: 4px 0 0; line-height: 1;">{{ printingStudent.academic_year }}</p>
                        <div style="width: 28px; height: 2px; background: #ffffff; margin: 5px auto;"></div>
                        <p style="font-size: 8px; font-weight: 700; color: rgba(255,255,255,0.85); letter-spacing: 0.1em; margin: 0; text-transform: uppercase;">Official Copy</p>
                    </div>
                </div>

                <!-- META STRIP -->
                <div style="
                    display: flex; justify-content: space-between; align-items: center;
                    padding: 8px 20px;
                    background: #ecfdf5;
                    border-left: 4px solid #047857;
                    border-right: 4px solid #047857;
                    border-bottom: 3px solid #047857;
                    font-size: 11px;
                    color: #064e3b;
                    margin-bottom: 14px;
                    border-radius: 0 0 8px 8px;
                ">
                    <div>
                        <span style="font-weight: 700; color: #047857; letter-spacing: 0.05em;">Examination: </span>
                        <span style="font-weight: 800; color: #064e3b; text-transform: uppercase;">{{ activeExamLabel }}</span>
                    </div>
                    <div>
                        <span style="font-weight: 700; color: #047857; letter-spacing: 0.05em;">Date: </span>
                        <span style="font-weight: 800; color: #064e3b;">{{ new Date().toLocaleDateString('en-GB') }}</span>
                    </div>
                </div>

                <!-- STUDENT INFO + GRADE SCALE -->
                <div style="display: flex; gap: 12px; margin-bottom: 14px;">
                    <div style="flex: 1.55; border: 2px solid #047857; border-radius: 10px; overflow: hidden; background: #ffffff;">
                        <div style="background: linear-gradient(135deg, #047857 0%, #0f766e 100%); color: #ffffff; padding: 7px 14px;">
                            <h3 style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; margin: 0;">
                                Student Information
                            </h3>
                        </div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <tr>
                                <td style="width: 100px; padding: 6px 12px; background: #f0fdfa; font-weight: 700; color: #047857; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0;">Name</td>
                                <td style="padding: 6px 12px; font-weight: 700; color: #064e3b; border-bottom: 1px solid #a7f3d0;">{{ printingStudent.student_name }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 6px 12px; background: #f0fdfa; font-weight: 700; color: #047857; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0;">Father's Name</td>
                                <td style="padding: 6px 12px; color: #1e293b; border-bottom: 1px solid #a7f3d0;">{{ printingStudent.father_name || '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 6px 12px; background: #f0fdfa; font-weight: 700; color: #047857; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0;">Mother's Name</td>
                                <td style="padding: 6px 12px; color: #1e293b; border-bottom: 1px solid #a7f3d0;">{{ printingStudent.mother_name || '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 6px 12px; background: #f0fdfa; font-weight: 700; color: #047857; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0;">Class</td>
                                <td style="padding: 6px 12px; font-weight: 700; color: #064e3b; border-bottom: 1px solid #a7f3d0;">{{ printingStudent.class }} - {{ printingStudent.section || 'N/A' }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 6px 12px; background: #f0fdfa; font-weight: 700; color: #047857; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0;">Roll No.</td>
                                <td style="padding: 6px 12px; font-weight: 700; color: #064e3b; border-bottom: 1px solid #a7f3d0;">{{ printingStudent.roll_number || '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 6px 12px; background: #f0fdfa; font-weight: 700; color: #047857; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0;">Student ID</td>
                                <td style="padding: 6px 12px; font-family: monospace; color: #1e293b; border-bottom: 1px solid #a7f3d0;">{{ printingStudent.student_id }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 6px 12px; background: #f0fdfa; font-weight: 700; color: #047857; border-right: 1px solid #a7f3d0;">Date of Birth</td>
                                <td style="padding: 6px 12px; color: #1e293b;">{{ printingStudent.date_of_birth || '—' }}</td>
                            </tr>
                        </table>
                    </div>

                    <div style="flex: 1; border: 2px solid #047857; border-radius: 10px; overflow: hidden; background: #ffffff;">
                        <div style="background: linear-gradient(135deg, #047857 0%, #0f766e 100%); color: #ffffff; padding: 7px 14px;">
                            <h3 style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; margin: 0;">
                                Grading Scale
                            </h3>
                        </div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                            <thead>
                                <tr style="background: #f0fdfa;">
                                    <th style="padding: 4px 6px; text-align: center; font-weight: 800; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0; color: #047857; font-size: 9px; letter-spacing: 0.05em;">MARKS</th>
                                    <th style="padding: 4px 6px; text-align: center; font-weight: 800; border-bottom: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0; color: #047857; font-size: 9px; letter-spacing: 0.05em;">LG</th>
                                    <th style="padding: 4px 6px; text-align: center; font-weight: 800; border-bottom: 1px solid #a7f3d0; color: #047857; font-size: 9px; letter-spacing: 0.05em;">GPA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding: 4px 6px; text-align: center; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; color: #475569;">80-100</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #047857; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; background: #d1fae5;">A+</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #0f766e; border-bottom: 1px solid #e2e8f0;">5.00</td>
                                </tr>
                                <tr>
                                    <td style="padding: 4px 6px; text-align: center; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; color: #475569;">70-79</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #15803d; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;">A</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #0f766e; border-bottom: 1px solid #e2e8f0;">4.00</td>
                                </tr>
                                <tr>
                                    <td style="padding: 4px 6px; text-align: center; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; color: #475569;">60-69</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #4d7c0f; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;">A-</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #0f766e; border-bottom: 1px solid #e2e8f0;">3.50</td>
                                </tr>
                                <tr>
                                    <td style="padding: 4px 6px; text-align: center; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; color: #475569;">50-59</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #a16207; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;">B</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #0f766e; border-bottom: 1px solid #e2e8f0;">3.00</td>
                                </tr>
                                <tr>
                                    <td style="padding: 4px 6px; text-align: center; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; color: #475569;">40-49</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #c2410c; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;">C</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #0f766e; border-bottom: 1px solid #e2e8f0;">2.00</td>
                                </tr>
                                <tr>
                                    <td style="padding: 4px 6px; text-align: center; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; color: #475569;">33-39</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #be123c; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;">D</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #0f766e; border-bottom: 1px solid #e2e8f0;">1.00</td>
                                </tr>
                                <tr style="background: #fef2f2;">
                                    <td style="padding: 4px 6px; text-align: center; border-right: 1px solid #e2e8f0; color: #b91c1c;">0-32</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #b91c1c; border-right: 1px solid #e2e8f0; background: #fee2e2;">F</td>
                                    <td style="padding: 4px 6px; text-align: center; font-weight: 800; color: #b91c1c;">0.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SUBJECT TABLE -->
                <div style="border: 2px solid #047857; border-radius: 10px; overflow: hidden; background: #ffffff; margin-bottom: 14px;">
                    <div style="background: linear-gradient(135deg, #047857 0%, #0f766e 100%); color: #ffffff; padding: 7px 14px;">
                        <h3 style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; margin: 0;">
                            Subject-wise Performance
                        </h3>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                        <thead>
                            <tr style="background: #ecfdf5;">
                                <th style="padding: 7px 12px; text-align: left; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #047857; border-bottom: 1.5px solid #047857; border-right: 1px solid #a7f3d0;">Subject</th>
                                <th style="padding: 7px 12px; text-align: center; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #047857; border-bottom: 1.5px solid #047857; border-right: 1px solid #a7f3d0; width: 70px;">Total</th>
                                <th style="padding: 7px 12px; text-align: center; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #047857; border-bottom: 1.5px solid #047857; border-right: 1px solid #a7f3d0; width: 90px;">Obtained</th>
                                <th style="padding: 7px 12px; text-align: center; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #047857; border-bottom: 1.5px solid #047857; border-right: 1px solid #a7f3d0; width: 90px;">Highest</th>
                                <th style="padding: 7px 12px; text-align: center; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #047857; border-bottom: 1.5px solid #047857; border-right: 1px solid #a7f3d0; width: 60px;">LG</th>
                                <th style="padding: 7px 12px; text-align: center; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #047857; border-bottom: 1.5px solid #047857; width: 60px;">GPA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(sub, idx) in printingStudent.subjects" :key="sub.subject" :style="idx % 2 === 0 ? 'background: #ffffff;' : 'background: #f0fdfa;'">
                                <td style="padding: 7px 12px; font-weight: 700; color: #064e3b; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">{{ sub.subject }}</td>
                                <td style="padding: 7px 12px; text-align: center; color: #475569; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">100</td>
                                <td style="padding: 7px 12px; text-align: center; font-weight: 800; color: #047857; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">{{ sub.marks ?? '—' }}</td>
                                <td style="padding: 7px 12px; text-align: center; color: #475569; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">{{ sub.highest ?? '—' }}</td>
                                <td style="padding: 7px 12px; text-align: center; font-weight: 800; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;"
                                    :style="sub.grade === 'F' ? 'color: #b91c1c; background: #fee2e2;' : 'color: #047857;'">
                                    {{ sub.grade }}
                                </td>
                                <td style="padding: 7px 12px; text-align: center; font-weight: 800; border-bottom: 1px solid #d1fae5;"
                                    :style="sub.grade === 'F' ? 'color: #b91c1c; background: #fee2e2;' : 'color: #0f766e;'">
                                    {{ sub.gpa !== null ? sub.gpa.toFixed(2) : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- OVERALL RESULT -->
                <div style="border: 2px solid #047857; border-radius: 10px; overflow: hidden; background: #ffffff; margin-bottom: 14px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 20%; padding: 14px 8px; text-align: center; background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%); border-right: 1px solid #a7f3d0;">
                                <p style="font-size: 9px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;">Total Marks</p>
                                <p style="font-size: 26px; font-weight: 900; color: #047857; margin: 0; line-height: 1;">
                                    {{ getStudentTotalMarks(printingStudent) }}
                                </p>
                                <p style="font-size: 9px; color: #0f766e; margin: 4px 0 0; font-weight: 600;">
                                    of {{ (printingStudent.subjects || []).length * 100 }}
                                </p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center; background: linear-gradient(180deg, #fffbeb 0%, #fef3c7 100%); border-right: 1px solid #a7f3d0;">
                                <p style="font-size: 9px; font-weight: 800; color: #92400e; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;">Position</p>
                                <p style="font-size: 26px; font-weight: 900; color: #92400e; margin: 0; line-height: 1;">
                                    {{ getOrdinal(getStudentRank(printingStudent)) }}
                                </p>
                                <p style="font-size: 9px; color: #b45309; margin: 4px 0 0; font-weight: 600;">
                                    out of {{ students.total || 0 }}
                                </p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center; background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%); border-right: 1px solid #a7f3d0;">
                                <p style="font-size: 9px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;">Overall GPA</p>
                                <p style="font-size: 26px; font-weight: 900; color: #047857; margin: 0; line-height: 1;">{{ printingStudent.overall_gpa }}</p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center; background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%); border-right: 1px solid #a7f3d0;">
                                <p style="font-size: 9px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;">Letter Grade</p>
                                <p style="font-size: 26px; font-weight: 900; margin: 0; line-height: 1;"
                                   :style="printingStudent.has_fail ? 'color: #b91c1c;' : 'color: #047857;'">
                                    {{ printingStudent.overall_letter }}
                                </p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center;"
                                :style="printingStudent.has_fail ? 'background: linear-gradient(180deg, #fef2f2 0%, #fee2e2 100%);' : 'background: linear-gradient(180deg, #ecfdf5 0%, #bbf7d0 100%);'">
                                <p style="font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;"
                                   :style="printingStudent.has_fail ? 'color: #b91c1c;' : 'color: #047857;'">
                                    Result
                                </p>
                                <p style="font-size: 18px; font-weight: 900; margin: 0; line-height: 1; letter-spacing: 0.05em;"
                                   :style="printingStudent.has_fail ? 'color: #b91c1c;' : 'color: #047857;'">
                                    {{ printingStudent.has_fail ? 'FAILED' : 'PASSED' }}
                                </p>
                                <p style="font-size: 10px; font-weight: 700; margin: 5px 0 0;"
                                   :style="printingStudent.has_fail ? 'color: #b91c1c;' : 'color: #0f766e;'">
                                    ({{ getStudentTotalMarks(printingStudent) }} Marks)
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- SIGNATURES -->
                <div style="margin-top: auto; padding-top: 50px; display: flex; justify-content: space-between; gap: 30px; flex-shrink: 0;">
                    <div style="flex: 1; text-align: center;">
                        <div style="border-top: 1.5px solid #047857; padding-top: 6px;">
                            <p style="font-size: 10px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0;">Class Teacher</p>
                        </div>
                    </div>
                    <div style="flex: 1; text-align: center;">
                        <div style="border-top: 1.5px solid #047857; padding-top: 6px;">
                            <p style="font-size: 10px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0;">Exam Controller</p>
                        </div>
                    </div>
                    <div style="flex: 1; text-align: center;">
                        <div style="border-top: 1.5px solid #047857; padding-top: 6px;">
                            <p style="font-size: 10px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0;">Principal</p>
                        </div>
                    </div>
                </div>

                <!-- FOOTER -->
                <div style="margin-top: 18px; padding-top: 10px; border-top: 1px dashed #047857; display: flex; justify-content: space-between; font-size: 9px; color: #475569; flex-shrink: 0;">
                    <p style="margin: 0; font-weight: 600;">Printed on: {{ new Date().toLocaleString() }}</p>
                    <p style="margin: 0; font-style: italic;">This is a computer-generated document. No signature required.</p>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>