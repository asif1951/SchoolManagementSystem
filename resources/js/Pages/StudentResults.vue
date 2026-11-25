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
    groups: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    mediums: { type: Array, default: () => [] },
    classSections: { type: Object, default: () => ({}) },
    // 🆕 Class / Section filter options for the class list (from backend)
    filterClassNames: { type: Array, default: () => [] },
    filterSections: { type: Array, default: () => [] },
    filterHasNoSection: { type: Boolean, default: false },
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

// ============================================================
// 🆕 Step 1: Class + Section Filter State (Backend)
// ============================================================
const filterClassName = ref(props.filters.class_name || '')
const filterSection = ref(props.filters.section || '')
const filterGroup = ref(props.filters.group || '')
const filterShift = ref(props.filters.shift || '')
const filterMedium = ref(props.filters.medium || '')

const hasClassFilters = computed(() => !!(filterClassName.value || filterSection.value || filterGroup.value || filterShift.value || filterMedium.value))

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
    // 🆕 Year change → class/section options change, so reset them
    filterClassName.value = ''
    filterSection.value = ''
    filterGroup.value = ''
    filterShift.value = ''
    filterMedium.value = ''

    router.get(route('student.results'), {
        academic_year: selectedYear.value,
    }, {
        preserveScroll: true,
        preserveState: true,
    })
}

// 🆕 Fetch classes with year + class + section filters (backend)
const fetchClasses = () => {
    router.get(route('student.results'), {
        academic_year: selectedYear.value,
        class_name: filterClassName.value || undefined,
        section: filterSection.value || undefined,
        group: filterGroup.value || undefined,
        shift: filterShift.value || undefined,
        medium: filterMedium.value || undefined,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}

// 🆕 Class changed → reset section, reload from backend
const handleFilterGroupChange = () => {
    fetchClasses()
}

const handleFilterClassChange = () => {
    filterSection.value = ''
    fetchClasses()
}

// 🆕 Section changed → reload from backend
const handleFilterSectionChange = () => {
    fetchClasses()
}

// 🆕 Clear class + section filters
const resetClassFilters = () => {
    filterClassName.value = ''
    filterSection.value = ''
    filterGroup.value = ''
    filterShift.value = ''
    filterMedium.value = ''
    fetchClasses()
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
// ============================================================
// Expandable subject rows (the table shows one line per student; the subject marks open on demand)
// ============================================================
const expandedIds = ref([])

const isExpanded = (id) => expandedIds.value.includes(id)

const toggleExpand = (id) => {
    expandedIds.value = isExpanded(id)
        ? expandedIds.value.filter(x => x !== id)
        : [...expandedIds.value, id]
}

const expandAll = () => {
    expandedIds.value = studentsList.value.map(s => s.id)
}

const collapseAll = () => {
    expandedIds.value = []
}
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

        <!-- Notification -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="toast.show" class="fixed top-4 left-1/2 z-[9999] w-full max-w-md -translate-x-1/2 px-3 no-print">
                <div
                    :class="[
                        'flex items-start gap-3 rounded-md border border-l-4 bg-white p-3 text-sm shadow-lg dark:bg-slate-900',
                        toast.type === 'success' ? 'border-slate-200 border-l-green-600 dark:border-slate-700 dark:border-l-green-500' : '',
                        toast.type === 'error' ? 'border-slate-200 border-l-red-600 dark:border-slate-700 dark:border-l-red-500' : '',
                        toast.type === 'info' ? 'border-slate-200 border-l-blue-600 dark:border-slate-700 dark:border-l-blue-500' : '',
                    ]"
                >
                    <svg v-if="toast.type === 'success'" class="mt-0.5 h-5 w-5 flex-shrink-0 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    <svg v-else-if="toast.type === 'error'" class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    <svg v-else class="mt-0.5 h-5 w-5 flex-shrink-0 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <p class="min-w-0 flex-1 break-words text-slate-800 dark:text-slate-100">{{ toast.message }}</p>
                    <button type="button" @click="toast.show = false" class="flex-shrink-0 rounded p-0.5 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" title="Dismiss">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
        </Transition>

        <div class="min-h-screen w-full bg-slate-50 text-slate-800 no-print dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-[1500px] px-4 py-5 sm:px-6 lg:px-8 lg:py-7">

                <!-- ============================ VIEW 1: CLASS LIST ============================ -->
                <template v-if="!classInfo">
                    <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                        <div>
                            <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Student Results</h1>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Select a class to review its examination results.</p>
                        </div>
                        <a
                            :href="route('reports.class-wise', { academic_year: selectedYear })"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                            Class-wise result (A4)
                        </a>
                    </header>

                    <div v-if="flash?.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm text-slate-800 dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900 dark:text-slate-100">{{ flash.success }}</div>
                    <div v-if="flash?.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm text-slate-800 dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900 dark:text-slate-100">{{ flash.error }}</div>

                    <!-- Filters -->
                    <section class="mb-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-end gap-3">
                            <!-- Academic year -->
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Academic year</label>
                                <div class="flex items-center gap-1">
                                    <button type="button" @click="decrementYear" title="Previous year" class="flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                                    </button>

                                    <div class="relative" @focusout="setTimeout(() => showYearDropdown = false, 200)">
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
                                            class="h-9 w-28 rounded-md border border-slate-300 bg-white px-3 text-center text-sm font-semibold tracking-wider text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                        />
                                        <div v-if="showYearDropdown && filteredYearSuggestions.length > 0" class="absolute left-0 top-full z-50 mt-1 w-full overflow-hidden rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                            <div class="max-h-60 overflow-y-auto py-1">
                                                <button
                                                    v-for="year in filteredYearSuggestions"
                                                    :key="year"
                                                    type="button"
                                                    @mousedown.prevent="selectYearSuggestion(year)"
                                                    class="flex w-full items-center justify-between px-3 py-1.5 text-left text-sm"
                                                    :class="year === selectedYear ? 'bg-blue-700 text-white' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800'"
                                                >
                                                    <span>{{ year }}</span>
                                                    <span v-if="year === new Date().getFullYear()" class="text-[10px] font-semibold uppercase tracking-wide" :class="year === selectedYear ? 'text-blue-100' : 'text-slate-400'">Current</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="button" @click="incrementYear" title="Next year" class="flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                    </button>
                                </div>
                            </div>

                            <div v-if="groups.length" class="w-full sm:w-40">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Study group</label>
                                <select v-model="filterGroup" @change="handleFilterGroupChange" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="">All groups</option>
                                    <option v-for="g in groups" :key="g.id" :value="String(g.id)">{{ g.name }}</option>
                                </select>
                            </div>

                            <div v-if="shifts.length > 1" class="w-full sm:w-36">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Shift</label>
                                <select v-model="filterShift" @change="fetchClasses" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="">All shifts</option>
                                    <option v-for="x in shifts" :key="x.id" :value="String(x.id)">{{ x.name }}</option>
                                </select>
                            </div>

                            <div v-if="mediums.length > 1" class="w-full sm:w-36">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Medium</label>
                                <select v-model="filterMedium" @change="fetchClasses" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="">All mediums</option>
                                    <option v-for="x in mediums" :key="x.id" :value="String(x.id)">{{ x.name }}</option>
                                </select>
                            </div>

                            <div class="w-full sm:w-44">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Class</label>
                                <select v-model="filterClassName" @change="handleFilterClassChange" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="">All classes</option>
                                    <option v-for="cn in filterClassNames" :key="cn" :value="cn">{{ cn }}</option>
                                </select>
                            </div>

                            <div class="w-full sm:w-40">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Section</label>
                                <select v-model="filterSection" @change="handleFilterSectionChange" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="">All sections</option>
                                    <option v-for="sec in filterSections" :key="sec" :value="sec">{{ sec }}</option>
                                    <option v-if="filterHasNoSection" value="__none__">No section</option>
                                </select>
                            </div>

                            <button v-if="hasClassFilters" type="button" @click="resetClassFilters" class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                Clear filters
                            </button>
                        </div>
                    </section>

                    <!-- No classes -->
                    <div v-if="classes.length === 0" class="rounded-md border border-slate-200 bg-white px-6 py-14 text-center dark:border-slate-800 dark:bg-slate-900">
                        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">No classes found</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">There are no classes for the academic year <strong>{{ selectedYear }}</strong> with these filters.</p>
                    </div>

                    <!-- Class table -->
                    <section v-else class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Classes — {{ selectedYear }}</h2>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ classes.length }} {{ classes.length === 1 ? 'class' : 'classes' }}</span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[760px] text-sm">
                                <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th class="px-4 py-2.5">Class</th>
                                        <th class="px-4 py-2.5">Section</th>
                                        <th class="px-4 py-2.5">Group</th>
                                        <th class="px-4 py-2.5">Shift · Medium</th>
                                        <th v-if="isAdmin" class="px-4 py-2.5">Class teacher</th>
                                        <th class="px-4 py-2.5 text-right">Subjects</th>
                                        <th class="px-4 py-2.5 text-right">Students</th>
                                        <th class="px-4 py-2.5"><span class="sr-only">Open</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                    <tr
                                        v-for="cls in classes"
                                        :key="cls.id"
                                        @click="openClass(cls.id)"
                                        class="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/60"
                                    >
                                        <td class="px-4 py-3 font-semibold text-slate-900 dark:text-white">{{ cls.class_label || cls.class_name }}</td>
                                        <td class="px-4 py-3">{{ cls.section || '—' }}</td>
                                        <td class="px-4 py-3">{{ cls.group || '—' }}</td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ [cls.shift, cls.medium].filter(Boolean).join(' · ') || '—' }}</td>
                                        <td v-if="isAdmin" class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ cls.class_teacher || 'Unassigned' }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ cls.subjects?.length || 0 }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ cls.student_count }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <button type="button" @click.stop="openClass(cls.id)" class="rounded-md border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">View results</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>

                <!-- ============================ VIEW 2: CLASS RESULT ============================ -->
                <template v-else>
                    <button type="button" @click="backToClasses" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-blue-700 hover:underline dark:text-blue-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                        All classes
                    </button>

                    <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                        <div class="min-w-0">
                            <h1 class="text-xl font-semibold text-slate-900 dark:text-white">
                                {{ classInfo.class_label || classInfo.class_name }}<template v-if="classInfo.section"> — Section {{ classInfo.section_label || classInfo.section }}</template>
                            </h1>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                                Academic year {{ classInfo.academic_year }}
                                <template v-if="classInfo.group"> · {{ classInfo.group }}</template>
                                <template v-if="classInfo.shift"> · {{ classInfo.shift }}</template>
                                <template v-if="classInfo.medium"> · {{ classInfo.medium }} medium</template>
                                · {{ totalStudents }} students
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a :href="route('tabulation.show', { class: classInfo.id, exam_type: selectedExam })" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Tabulation sheet</a>
                            <a :href="route('reports.marksheet.class', { class: classInfo.id, exam_type: selectedExam })" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Marksheets</a>
                            <a :href="route('reports.summary.class', { class: classInfo.id, exam_type: selectedExam })" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Result summary</a>
                        </div>
                    </header>

                    <div v-if="flash?.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm text-slate-800 dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900 dark:text-slate-100">{{ flash.success }}</div>
                    <div v-if="flash?.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm text-slate-800 dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900 dark:text-slate-100">{{ flash.error }}</div>

                    <!-- Figures -->
                    <section class="mb-4 grid grid-cols-2 divide-x divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200 bg-white sm:grid-cols-4 sm:divide-y-0 dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
                        <div class="px-4 py-3">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Students shown</p>
                            <p class="mt-0.5 text-xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ students.total || 0 }} <span class="text-sm font-normal text-slate-400">/ {{ totalStudents }}</span></p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Passed</p>
                            <p class="mt-0.5 text-xl font-semibold tabular-nums text-green-700 dark:text-green-400">{{ passedCount }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Failed</p>
                            <p class="mt-0.5 text-xl font-semibold tabular-nums text-red-700 dark:text-red-400">{{ studentsList.filter(s => s.has_fail).length }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Examination</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ activeExamLabel }}</p>
                        </div>
                    </section>

                    <!-- Search and exam -->
                    <section class="mb-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-12">
                            <div class="md:col-span-5">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Student name or ID</label>
                                <div class="relative">
                                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                    <input v-model="search" type="text" placeholder="Search…" class="h-9 w-full rounded-md border border-slate-300 bg-white pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                                </div>
                            </div>
                            <div class="md:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Roll number</label>
                                <input v-model="rollNumber" type="text" placeholder="Roll…" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                            </div>
                            <div class="md:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Examination</label>
                                <select v-model="selectedExam" @change="handleExamChange" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option v-for="et in examTypes" :key="et.value" :value="et.value">{{ et.label }}</option>
                                </select>
                            </div>
                            <div class="flex items-end md:col-span-1">
                                <button type="button" @click="resetStudentFilters" :disabled="!hasActiveFilters" title="Reset filters" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Reset</button>
                            </div>
                        </div>

                        <div v-if="hasActiveFilters" class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3 text-xs dark:border-slate-800">
                            <span class="font-medium text-slate-500 dark:text-slate-400">Filters:</span>
                            <span v-if="search" class="inline-flex items-center gap-1 rounded border border-slate-300 bg-slate-50 px-2 py-0.5 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                Name: {{ search }}
                                <button type="button" @click="search = ''; applyStudentFilters()" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-100" title="Remove"><svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg></button>
                            </span>
                            <span v-if="rollNumber" class="inline-flex items-center gap-1 rounded border border-slate-300 bg-slate-50 px-2 py-0.5 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                Roll: {{ rollNumber }}
                                <button type="button" @click="rollNumber = ''; applyStudentFilters()" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-100" title="Remove"><svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg></button>
                            </span>
                        </div>
                    </section>

                    <!-- Promotion -->
                    <section class="mb-4 rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Move students to the next year</h2>
                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                <span class="font-semibold tabular-nums">{{ props.classInfo?.academic_year || props.filters?.academic_year || '—' }}</span>
                                <span class="mx-1.5 text-slate-400">→</span>
                                <span class="font-semibold tabular-nums">{{ nextYear }}</span>
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="selectAllPassed" :disabled="passedCount === 0" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                    Select all passed ({{ passedCount }})
                                </button>
                                <button v-if="selectedCount > 0" type="button" @click="clearSelection" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">Clear selection</button>
                                <span v-if="selectedCount > 0" class="text-sm font-semibold text-blue-700 dark:text-blue-400">{{ selectedCount }} selected</span>
                            </div>

                            <div class="flex-1"></div>

                            <div class="flex flex-wrap items-center gap-2">
                                <select v-model="targetClass" :disabled="selectedCount === 0" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 disabled:opacity-50 sm:w-44 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="">Target class</option>
                                    <option v-for="c in classNames" :key="c" :value="c">{{ c }}</option>
                                </select>
                                <select v-model="targetSection" :disabled="selectedCount === 0 || !targetClass" class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 disabled:opacity-50 sm:w-32 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="">Section</option>
                                    <option v-for="s in availableSections" :key="s" :value="s">{{ s }}</option>
                                </select>
                                <button type="button" @click="shiftSelected" :disabled="selectedCount === 0 || !targetClass || shifting" class="inline-flex h-9 items-center gap-2 whitespace-nowrap rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50">
                                    <svg v-if="shifting" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    {{ shifting ? 'Moving…' : `Move ${selectedCount || ''} to ${nextYear}`.replace('  ', ' ') }}
                                </button>
                            </div>
                        </div>

                        <p class="border-t border-slate-200 px-4 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            The original records stay untouched; the students are added to the new year's class and keep their ID and roll number.
                        </p>
                    </section>

                    <!-- Empty -->
                    <div v-if="studentsList.length === 0" class="rounded-md border border-slate-200 bg-white px-6 py-14 text-center dark:border-slate-800 dark:bg-slate-900">
                        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ hasActiveFilters ? 'No matching students' : 'No students found' }}</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ hasActiveFilters ? 'Try changing the search or the filters.' : 'This class has no active students.' }}</p>
                        <button v-if="hasActiveFilters" type="button" @click="resetStudentFilters" class="mt-4 inline-flex h-9 items-center rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800">Reset filters</button>
                    </div>

                    <!-- Results table -->
                    <section v-else class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ activeExamLabel }} — results</h2>
                            <div class="flex items-center gap-3 text-xs font-medium">
                                <button type="button" @click="expandAll" class="text-blue-700 hover:underline dark:text-blue-400">Show all subjects</button>
                                <span class="text-slate-300 dark:text-slate-700">|</span>
                                <button type="button" @click="collapseAll" class="text-blue-700 hover:underline dark:text-blue-400">Hide subjects</button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[920px] text-sm">
                                <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th class="w-10 px-3 py-2.5"><span class="sr-only">Select</span></th>
                                        <th class="px-3 py-2.5">Roll</th>
                                        <th class="px-3 py-2.5">Student</th>
                                        <th class="px-3 py-2.5 text-right">Total</th>
                                        <th class="px-3 py-2.5 text-right">GPA</th>
                                        <th class="px-3 py-2.5 text-center">Grade</th>
                                        <th class="px-3 py-2.5 text-center">Position</th>
                                        <th class="px-3 py-2.5 text-center">Result</th>
                                        <th class="px-3 py-2.5 text-right">Actions</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                    <template v-for="student in studentsList" :key="student.id">
                                        <tr :class="isStudentSelected(student.id) ? 'bg-blue-50 dark:bg-blue-950/30' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50'">
                                            <td class="px-3 py-2.5">
                                                <input
                                                    type="checkbox"
                                                    :checked="isStudentSelected(student.id)"
                                                    :disabled="student.has_fail || student.is_shifted"
                                                    :title="student.has_fail ? 'Failed — cannot be moved' : student.is_shifted ? `Already moved to ${student.shifted_to_class} (${student.shifted_to_year})` : 'Select to move to the next year'"
                                                    @change="toggleStudent(student.id)"
                                                    class="h-4 w-4 cursor-pointer rounded border-slate-300 text-blue-700 focus:ring-blue-600 disabled:cursor-not-allowed disabled:opacity-30"
                                                />
                                            </td>
                                            <td class="px-3 py-2.5 tabular-nums">{{ student.roll_number || '—' }}</td>
                                            <td class="px-3 py-2.5">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-200 text-xs font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                                                        <img v-if="student.student_photo" :src="'/storage/' + student.student_photo" :alt="student.student_name" class="h-full w-full object-cover" />
                                                        <span v-else>{{ student.student_name?.charAt(0)?.toUpperCase() }}</span>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <p class="truncate font-semibold text-slate-900 dark:text-white">{{ student.student_name }}</p>
                                                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ student.student_id }}</p>
                                                        <p v-if="student.is_shifted" class="text-xs font-medium text-amber-700 dark:text-amber-400">Moved to {{ student.shifted_to_class }} ({{ student.shifted_to_year }})</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2.5 text-right tabular-nums">{{ getStudentTotalMarks(student) }}</td>
                                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ student.overall_gpa }}</td>
                                            <td class="px-3 py-2.5 text-center font-semibold" :class="student.has_fail ? 'text-red-700 dark:text-red-400' : ''">{{ student.overall_letter }}</td>
                                            <td class="px-3 py-2.5 text-center tabular-nums">{{ getOrdinal(getStudentRank(student)) }}</td>
                                            <td class="px-3 py-2.5 text-center">
                                                <span
                                                    class="inline-block rounded border px-2 py-0.5 text-xs font-semibold"
                                                    :class="student.has_fail
                                                        ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300'
                                                        : 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'"
                                                >{{ student.has_fail ? 'Failed' : 'Passed' }}</span>
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <button type="button" @click="toggleExpand(student.id)" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                                                        {{ isExpanded(student.id) ? 'Hide subjects' : 'Subjects' }}
                                                    </button>
                                                    <a :href="route('reports.marksheet.student', { student: student.id, exam_type: selectedExam })" target="_blank" rel="noopener" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Marksheet</a>
                                                    <button type="button" @click="printResult(student)" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Print</button>
                                                    <button v-if="student.is_shifted" type="button" @click="resetShift(student)" :title="`Remove the move record for ${student.student_name} (${student.shifted_to_year})`" class="rounded-md border border-amber-300 px-2.5 py-1 text-xs font-medium text-amber-800 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-950/30">Undo move</button>
                                                </div>
                                            </td>
                                        </tr>

                                        <tr v-if="isExpanded(student.id)">
                                            <td colspan="9" class="bg-slate-50 px-4 py-3 dark:bg-slate-950/40">
                                                <div class="overflow-x-auto">
                                                    <table class="w-full min-w-[480px] border border-slate-200 bg-white text-xs dark:border-slate-700 dark:bg-slate-900">
                                                        <thead class="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                                            <tr>
                                                                <th class="border-b border-slate-200 px-3 py-1.5 text-left font-semibold dark:border-slate-700">Subject</th>
                                                                <th class="border-b border-slate-200 px-3 py-1.5 text-right font-semibold dark:border-slate-700">Full marks</th>
                                                                <th class="border-b border-slate-200 px-3 py-1.5 text-right font-semibold dark:border-slate-700">Obtained</th>
                                                                <th class="border-b border-slate-200 px-3 py-1.5 text-right font-semibold dark:border-slate-700">Highest</th>
                                                                <th class="border-b border-slate-200 px-3 py-1.5 text-center font-semibold dark:border-slate-700">Grade</th>
                                                                <th class="border-b border-slate-200 px-3 py-1.5 text-right font-semibold dark:border-slate-700">Grade point</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                                            <tr v-for="sub in student.subjects" :key="sub.subject">
                                                                <td class="px-3 py-1.5 font-medium text-slate-800 dark:text-slate-100">{{ sub.subject }}</td>
                                                                <td class="px-3 py-1.5 text-right tabular-nums text-slate-500">100</td>
                                                                <td class="px-3 py-1.5 text-right font-semibold tabular-nums" :class="sub.grade === 'F' ? 'text-red-700 dark:text-red-400' : 'text-slate-900 dark:text-white'">{{ sub.marks ?? '—' }}</td>
                                                                <td class="px-3 py-1.5 text-right tabular-nums text-slate-500">{{ sub.highest ?? '—' }}</td>
                                                                <td class="px-3 py-1.5 text-center font-semibold" :class="sub.grade === 'F' ? 'text-red-700 dark:text-red-400' : ''">{{ sub.grade }}</td>
                                                                <td class="px-3 py-1.5 text-right tabular-nums">{{ sub.gpa !== null ? sub.gpa.toFixed(2) : '—' }}</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="students?.links && students.links.length > 3" class="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">
                                Showing <span class="font-semibold text-slate-900 dark:text-white">{{ students.from ?? 0 }}</span>–<span class="font-semibold text-slate-900 dark:text-white">{{ students.to ?? 0 }}</span>
                                of <span class="font-semibold text-slate-900 dark:text-white">{{ students.total ?? 0 }}</span> students
                            </p>
                            <div class="flex flex-wrap items-center justify-center gap-1">
                                <template v-for="(link, index) in students.links" :key="index">
                                    <span v-if="link.url === null" class="cursor-not-allowed rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300 dark:border-slate-800 dark:text-slate-600" v-html="link.label"></span>
                                    <Link
                                        v-else
                                        :href="link.url"
                                        preserve-scroll
                                        preserve-state
                                        :class="[
                                            'rounded-md border px-3 py-1.5 text-sm font-medium',
                                            link.active
                                                ? 'border-blue-700 bg-blue-700 text-white'
                                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800'
                                        ]"
                                        v-html="link.label"
                                    />
                                </template>
                            </div>
                        </div>
                    </section>
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