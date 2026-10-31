<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
    classes: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    classInfo: { type: Object, default: null },
    students: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    totalStudents: { type: Number, default: 0 },
    subjects: { type: Array, default: () => [] },
    subjectLocks: { type: Object, default: () => ({}) },
    subjectHighest: { type: Object, default: () => ({}) },
    examType: { type: String, default: 'final' },
    examTypes: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    availableYears: { type: Array, default: () => [] },
    classNames: { type: Array, default: () => [] },
    sections: { type: Array, default: () => [] },
    selectedYear: { type: String, default: '' },
    isAdmin: { type: Boolean, default: false },
    flash: { type: Object, default: () => ({}) },
    schoolInfo: {
        type: Object,
        default: () => ({
            name: 'ChildHome Academy',
            address: '123 Education Road, City',
            phone: '+880 1234-567890',
        }),
    },
})

// ============================================================
// TOAST SYSTEM (inline)
// ============================================================
const toasts = ref([])
let toastId = 0

const showToast = (message, type = 'success') => {
    const isDuplicate = toasts.value.some(
        t => t.message === message && t.type === type
    )
    if (isDuplicate) return

    const id = ++toastId
    toasts.value.push({ id, message, type })
    setTimeout(() => {
        toasts.value = toasts.value.filter(t => t.id !== id)
    }, 3500)
}

const removeToast = (id) => {
    toasts.value = toasts.value.filter(t => t.id !== id)
}

watch(() => props.flash, (flash) => {
    if (flash?.success) showToast(flash.success, 'success')
    if (flash?.error) showToast(flash.error, 'error')
    if (flash?.warning) showToast(flash.warning, 'warning')
}, { deep: true, immediate: true })

// ============================================================
// ✅ Shared school info from backend
// ============================================================
const page = usePage()
const sharedSchool = computed(() => page.props.school ?? {})

const school = computed(() => ({
    name: sharedSchool.value.name || props.schoolInfo?.name || 'Your School',
    motto: sharedSchool.value.motto || props.schoolInfo?.motto || '',
    logo: sharedSchool.value.logo || props.schoolInfo?.logo || null,
    address: sharedSchool.value.address || props.schoolInfo?.address || '',
    phone: sharedSchool.value.phone || props.schoolInfo?.phone || '',
}))

const schoolLogoUrl = computed(() => {
    const logo = school.value.logo
    if (!logo) return null
    if (logo.startsWith('http')) return logo
    return `/storage/${logo.replace(/^\/+/, '')}`
})

// ============================================================
// Exam state
// ============================================================
const selectedExam = ref(props.examType || props.filters?.exam_type || 'final')

const examTypesComputed = computed(() => {
    if (props.examTypes && props.examTypes.length > 0) return props.examTypes
    return [
        { value: 'first_terminal', label: 'First Terminal' },
        { value: 'second_terminal', label: 'Second Terminal' },
        { value: 'final', label: 'Final Exam' },
        { value: 'model_test', label: 'Model Test' },
    ]
})

// ============================================================
// Year State
// ============================================================
const selectedYear = ref(
    String(props.selectedYear || props.filters?.academic_year || new Date().getFullYear())
)
const yearInput = ref(String(selectedYear.value))
const showYearDropdown = ref(false)

const yearSuggestions = computed(() => {
    const years = [...(props.availableYears || [])].map(String)
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
// Marks Data
// ============================================================
const marksData = ref({})

const studentsList = computed(() => props.students?.data || [])
const classList = computed(() => props.classes?.data || [])

const initMarksData = () => {
    const newData = {}
    studentsList.value.forEach(student => {
        newData[student.id] = {}
        props.subjects.forEach(subject => {
            const existing = student.marks?.[subject] ?? null
            newData[student.id][subject] = existing
        })
    })
    marksData.value = newData
}

initMarksData()

watch(() => props.students, () => {
    initMarksData()
}, { deep: false })

// ============================================================
// Print State
// ============================================================
const printingStudent = ref(null)

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
    const current = String(yearInput.value || selectedYear.value)
    yearInput.value = String(parseInt(current) + 1)
    applyYear()
}

const decrementYear = () => {
    const current = String(yearInput.value || selectedYear.value)
    yearInput.value = String(parseInt(current) - 1)
    applyYear()
}

watch(() => props.filters?.academic_year, (newYear) => {
    if (newYear && String(newYear) !== String(selectedYear.value)) {
        selectedYear.value = String(newYear)
        yearInput.value = String(newYear)
    }
})

watch(() => props.selectedYear, (newYear) => {
    if (newYear && String(newYear) !== String(selectedYear.value)) {
        selectedYear.value = String(newYear)
        yearInput.value = String(newYear)
    }
})

const handleYearChange = () => {
    // ✅ Year change hole class & section reset (year onujayi class alada)
    classFilter.value = ''
    sectionFilter.value = ''

    router.get(route('results.index'), {
        academic_year: selectedYear.value,
        search: classSearch.value || undefined,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}

// ============================================================
// Class Search + Class/Section Filter (Backend)
// ============================================================
const classSearch = ref(props.filters?.search || '')
const classFilter = ref(props.filters?.class_name || '')
const sectionFilter = ref(props.filters?.section || '')
let classSearchTimeout = null

watch(classSearch, () => {
    if (classSearchTimeout) clearTimeout(classSearchTimeout)
    classSearchTimeout = setTimeout(() => {
        fetchClasses(1)
    }, 500)
})

const fetchClasses = (page = 1) => {
    router.get(route('results.index'), {
        academic_year: selectedYear.value,
        class_name: classFilter.value || undefined,
        section: sectionFilter.value || undefined,
        search: classSearch.value || undefined,
        page,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

// ✅ Class select kora hole section reset hoy, tarpor backend theke notun list ashe
const handleClassFilterChange = () => {
    sectionFilter.value = ''
    fetchClasses(1)
}

const handleSectionFilterChange = () => {
    fetchClasses(1)
}

const resetClassSearch = () => {
    classSearch.value = ''
    classFilter.value = ''
    sectionFilter.value = ''
    fetchClasses(1)
}

// ============================================================
// Student Search (Backend) — Step 2
// ============================================================
const studentSearch = ref(props.filters?.search || '')
const rollNumber = ref(props.filters?.roll_number || '')

let studentSearchTimeout = null

watch([studentSearch, rollNumber], () => {
    if (!props.classInfo) return
    if (studentSearchTimeout) clearTimeout(studentSearchTimeout)
    studentSearchTimeout = setTimeout(() => {
        fetchStudents(1)
    }, 500)
})

const fetchStudents = (page = 1) => {
    if (!props.classInfo) return
    router.get(route('results.show', props.classInfo.id), {
        exam_type: selectedExam.value,
        search: studentSearch.value || undefined,
        roll_number: rollNumber.value || undefined,
        page,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const resetStudentSearch = () => {
    studentSearch.value = ''
    rollNumber.value = ''
    fetchStudents(1)
}

// ============================================================
// Navigation
// ============================================================
const openClass = (classId) => {
    router.visit(route('results.show', {
        classId: classId,
        exam_type: selectedExam.value,
    }))
}

const backToClasses = () => {
    router.visit(route('results.index'), {
        academic_year: selectedYear.value,
    })
}

const handleExamChange = () => {
    fetchStudents(1)
}

// ============================================================
// Helpers
// ============================================================
const getSubjectHighest = (subject) => {
    return props.subjectHighest?.[subject] ?? '—'
}

const getGradeInfo = (marks) => {
    if (marks === null || marks === '' || marks === undefined) {
        return { letter: '-', gpa: '-', color: 'text-slate-400 dark:text-slate-500', bg: 'bg-slate-50 dark:bg-slate-800/60', border: 'border-slate-200 dark:border-slate-700' }
    }
    const m = Number(marks)
    if (isNaN(m)) {
        return { letter: '-', gpa: '-', color: 'text-slate-400 dark:text-slate-500', bg: 'bg-slate-50 dark:bg-slate-800/60', border: 'border-slate-200 dark:border-slate-700' }
    }
    if (m >= 80) return { letter: 'A+', gpa: 5.00, color: 'text-emerald-700 dark:text-emerald-300', bg: 'bg-emerald-50 dark:bg-emerald-900/40', border: 'border-emerald-200 dark:border-emerald-800' }
    if (m >= 70) return { letter: 'A',  gpa: 4.00, color: 'text-green-700 dark:text-green-300',   bg: 'bg-green-50 dark:bg-green-900/40',   border: 'border-green-200 dark:border-green-800' }
    if (m >= 60) return { letter: 'A-', gpa: 3.50, color: 'text-lime-700 dark:text-lime-300',    bg: 'bg-lime-50 dark:bg-lime-900/40',    border: 'border-lime-200 dark:border-lime-800' }
    if (m >= 50) return { letter: 'B',  gpa: 3.00, color: 'text-yellow-700 dark:text-yellow-300',  bg: 'bg-yellow-50 dark:bg-yellow-900/40',  border: 'border-yellow-200 dark:border-yellow-800' }
    if (m >= 40) return { letter: 'C',  gpa: 2.00, color: 'text-orange-700 dark:text-orange-300',  bg: 'bg-orange-50 dark:bg-orange-900/40',  border: 'border-orange-200 dark:border-orange-800' }
    if (m >= 33) return { letter: 'D',  gpa: 1.00, color: 'text-rose-700 dark:text-rose-300',    bg: 'bg-rose-50 dark:bg-rose-900/40',    border: 'border-rose-200 dark:border-rose-800' }
    return              { letter: 'F',  gpa: 0.00, color: 'text-red-700 dark:text-red-300',     bg: 'bg-red-50 dark:bg-red-900/40',     border: 'border-red-200 dark:border-red-800' }
}

const getStudentOverall = (studentId) => {
    let totalGpa = 0
    let count = 0
    let hasFail = false

    props.subjects.forEach(subject => {
        const marks = marksData.value[studentId]?.[subject]
        if (marks !== null && marks !== '' && marks !== undefined) {
            const info = getGradeInfo(marks)
            if (info.gpa !== '-') {
                totalGpa += Number(info.gpa)
                count++
                if (info.letter === 'F') hasFail = true
            }
        }
    })

    if (hasFail) {
        return { gpa: '0.00', letter: 'F', hasFail: true, completed: count }
    }

    const overallGpa = count > 0 ? (totalGpa / count).toFixed(2) : '-'
    const letter = overallGpa === '-' ? '-' : getOverallLetter(Number(overallGpa))

    return { gpa: overallGpa, letter, hasFail: false, completed: count }
}

const getOverallLetter = (gpa) => {
    if (gpa >= 5.00) return 'A+'
    if (gpa >= 4.00) return 'A'
    if (gpa >= 3.50) return 'A-'
    if (gpa >= 3.00) return 'B'
    if (gpa >= 2.00) return 'C'
    if (gpa >= 1.00) return 'D'
    return 'F'
}

const getStudentTotalMarks = (studentId) => {
    const student = studentsList.value.find(s => s.id === studentId)
    if (student && student.total_marks !== undefined && student.total_marks !== null && student.total_marks > 0) {
        return student.total_marks
    }
    let total = 0
    props.subjects.forEach(subject => {
        const marks = marksData.value[studentId]?.[subject]
        if (marks !== null && marks !== '' && marks !== undefined) {
            total += Number(marks)
        }
    })
    return total
}

const getStudentTotalSubjects = (studentId) => {
    let count = 0
    props.subjects.forEach(subject => {
        const marks = marksData.value[studentId]?.[subject]
        if (marks !== null && marks !== '' && marks !== undefined) {
            count++
        }
    })
    return count
}

const getStudentRank = (studentId) => {
    const student = studentsList.value.find(s => s.id === studentId)
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

// ============================================================
// Lock helpers
// ============================================================
const isSubjectLocked = (subject) => {
    return props.subjectLocks?.[subject]?.is_locked ?? false
}

const getSubjectLockInfo = (subject) => {
    return props.subjectLocks?.[subject] ?? { is_locked: false, locked_at: null, locked_by: null }
}

const hasEditableSubject = computed(() => {
    if (props.isAdmin) return true
    return props.subjects.some(s => !isSubjectLocked(s))
})

const hasLockedSubject = computed(() => {
    return props.subjects.some(s => isSubjectLocked(s))
})

// ============================================================
// Class summary
// ============================================================
const classSummary = computed(() => {
    let totalGpa = 0
    let count = 0
    let passCount = 0
    let failCount = 0

    studentsList.value.forEach(student => {
        const overall = getStudentOverall(student.id)
        if (overall.gpa !== '-') {
            totalGpa += Number(overall.gpa)
            count++
            if (overall.hasFail) failCount++
            else passCount++
        }
    })

    return {
        averageGpa: count > 0 ? (totalGpa / count).toFixed(2) : '-',
        passCount,
        failCount,
        totalCount: studentsList.value.length,
    }
})

// ============================================================
// Exam label
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

// ============================================================
// Print
// ============================================================
const printStudent = (student) => {
    printingStudent.value = student
    setTimeout(() => {
        window.print()
        setTimeout(() => {
            printingStudent.value = null
        }, 500)
    }, 100)
}

// ============================================================
// Save results
// ============================================================
const saveResults = () => {
    const payload = []

    studentsList.value.forEach(student => {
        let totalMarks = 0
        props.subjects.forEach(subject => {
            if (isSubjectLocked(subject) && !props.isAdmin) return
            const marks = marksData.value[student.id]?.[subject]
            if (marks !== null && marks !== '' && marks !== undefined) {
                totalMarks += Number(marks)
            }
        })

        props.subjects.forEach(subject => {
            if (isSubjectLocked(subject) && !props.isAdmin) return
            const marks = marksData.value[student.id]?.[subject]
            if (marks !== null && marks !== '' && marks !== undefined) {
                payload.push({
                    student_id: student.id,
                    subject: subject,
                    marks: Number(marks),
                    total_marks: totalMarks,
                })
            }
        })
    })

    if (payload.length === 0) {
        showToast('কোনো mark input করা হয়নি বা সব mark locked!', 'error')
        return
    }

    router.post(route('results.store', props.classInfo.id), {
        exam_type: selectedExam.value,
        results: payload,
    }, {
        preserveScroll: true,
    })
}

const resetMarks = () => {
    if (!confirm('Reset all marks? This will clear all entered values (except locked subjects).')) return

    studentsList.value.forEach(student => {
        props.subjects.forEach(subject => {
            if (isSubjectLocked(subject) && !props.isAdmin) return
            marksData.value[student.id][subject] = null
        })
    })
}

// ============================================================
// Lock / Unlock
// ============================================================
const lockSubject = (subject) => {
    if (!confirm(`🔒 Lock "${subject}"? Once locked, no one can modify marks for this subject (except admin).`)) return

    router.post(route('results.lock', props.classInfo.id), {
        exam_type: selectedExam.value,
        subject: subject,
    }, {
        preserveScroll: true,
    })
}

const unlockSubject = (subject) => {
    if (!confirm(`🔓 Unlock "${subject}"? Teachers will be able to modify marks again.`)) return

    router.post(route('results.unlock', props.classInfo.id), {
        exam_type: selectedExam.value,
        subject: subject,
    }, {
        preserveScroll: true,
    })
}

// ============================================================
// Cleanup
// ============================================================
onBeforeUnmount(() => {
    printingStudent.value = null
    if (classSearchTimeout) clearTimeout(classSearchTimeout)
    if (studentSearchTimeout) clearTimeout(studentSearchTimeout)
})
</script>

<template>
    <Head :title="classInfo ? `Result Input - ${classInfo.class_name}` : 'Result Input'" />

    <AuthenticatedLayout>
        <component :is="'style'">
            .no-spinner::-webkit-outer-spin-button,
            .no-spinner::-webkit-inner-spin-button {
                -webkit-appearance: none;
                margin: 0;
            }
            .no-spinner {
                -moz-appearance: textfield;
                appearance: textfield;
            }

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

                body * {
                    visibility: hidden !important;
                }

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
                    font-size: 10px !important;
                    line-height: 1.3 !important;
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

        <div class="w-full px-3 py-4 sm:px-4 sm:py-6 md:px-6 lg:px-8 bg-gradient-to-br from-indigo-50/30 via-white to-purple-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 min-h-screen no-print transition-colors duration-300">

            <!-- ═══ TOAST CONTAINER ═══ -->
            <div class="fixed top-20 right-4 z-[9999] flex flex-col gap-2 w-[320px] max-w-[calc(100vw-2rem)] pointer-events-none">
                <TransitionGroup
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 translate-x-8"
                    enter-to-class="opacity-100 translate-x-0"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="opacity-100 translate-x-0"
                    leave-to-class="opacity-0 translate-x-8"
                    move-class="transition duration-200"
                >
                    <div
                        v-for="toast in toasts"
                        :key="toast.id"
                        class="pointer-events-auto flex items-start gap-3 rounded-xl border shadow-lg backdrop-blur-sm px-4 py-3"
                        :class="{
                            'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200': toast.type === 'success',
                            'bg-rose-50 dark:bg-rose-900/40 border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200': toast.type === 'error',
                            'bg-amber-50 dark:bg-amber-900/40 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200': toast.type === 'warning',
                        }"
                    >
                        <div
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-white text-xs font-bold"
                            :class="{
                                'bg-emerald-500': toast.type === 'success',
                                'bg-rose-500': toast.type === 'error',
                                'bg-amber-500': toast.type === 'warning',
                            }"
                        >
                            <svg v-if="toast.type === 'success'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else-if="toast.type === 'error'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 9v2m0 4h.01" />
                            </svg>
                        </div>
                        <p class="flex-1 text-sm font-medium leading-snug pt-0.5">{{ toast.message }}</p>
                        <button
                            type="button"
                            @click="removeToast(toast.id)"
                            class="shrink-0 rounded-md p-0.5 text-current/70 hover:bg-black/5 dark:hover:bg-white/10 transition"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </TransitionGroup>
            </div>

            <!-- VIEW 1: CLASS LIST -->
            <template v-if="!classInfo">
                <div class="mb-4 sm:mb-6">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg shadow-indigo-500/25 flex-shrink-0">
                            <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3 flex-wrap">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-indigo-800 to-purple-800 dark:from-indigo-300 dark:to-purple-300 bg-clip-text text-transparent truncate">
                                    Result Input Management
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
                                <span class="inline-block w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                {{ isAdmin ? 'All classes across the school' : 'Select a class to enter results' }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Year Filter -->
                <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/60 dark:border-indigo-800/60 shadow-sm p-4 sm:p-5 transition-colors duration-300">
                    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
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

                        <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full lg:w-auto">
                        <div class="relative flex items-center gap-2 w-full sm:w-auto">
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

                        <!-- ✅ Class & Section Filter (backend) -->
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <select
                                v-model="classFilter"
                                @change="handleClassFilterChange"
                                class="flex-1 sm:flex-none sm:w-40 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 px-3 py-2 sm:py-2.5 text-sm font-semibold text-indigo-800 dark:text-indigo-200 outline-none cursor-pointer focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                            >
                                <option value="">All Classes</option>
                                <option
                                    v-for="cn in classNames"
                                    :key="cn"
                                    :value="cn"
                                >
                                    {{ cn }}
                                </option>
                            </select>

                            <select
                                v-model="sectionFilter"
                                @change="handleSectionFilterChange"
                                :disabled="!classFilter"
                                class="flex-1 sm:flex-none sm:w-40 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 px-3 py-2 sm:py-2.5 text-sm font-semibold text-indigo-800 dark:text-indigo-200 outline-none cursor-pointer focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <option value="">{{ classFilter ? 'All Sections' : 'Select class first' }}</option>
                                <option
                                    v-for="sec in sections"
                                    :key="sec"
                                    :value="sec"
                                >
                                    {{ sec }}
                                </option>
                            </select>
                        </div>
                        </div>
                    </div>
                </div>

                <!-- Class Search -->
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
                            v-if="classSearch || classFilter || sectionFilter"
                            @click="resetClassSearch"
                            class="px-4 py-2 sm:py-2.5 text-sm font-medium text-rose-600 dark:text-rose-400 border-2 border-rose-200 dark:border-rose-800 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors flex-shrink-0"
                        >
                            ✕ Clear
                        </button>
                    </div>
                </div>

                <!-- No Classes -->
                <div v-if="classList.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-8 sm:p-12 border border-indigo-200/50 dark:border-indigo-800/60 shadow-sm text-center transition-colors duration-300">
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
                            {{ classes.total || 0 }} classes
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                        <button
                            v-for="cls in classList"
                            :key="cls.id"
                            @click="openClass(cls.id)"
                            class="group relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-6 border border-indigo-200/50 dark:border-indigo-800/60 shadow-sm hover:shadow-xl hover:border-purple-300 dark:hover:border-purple-700 transition-all duration-300 text-left overflow-hidden"
                        >
                            <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br from-indigo-400/20 to-purple-500/20 dark:from-indigo-600/20 dark:to-purple-700/20 group-hover:scale-150 transition-transform duration-500"></div>

                            <div class="relative">
                                <div class="flex items-start justify-between mb-3 sm:mb-4">
                                    <div class="p-2.5 sm:p-3 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 shadow-lg shadow-indigo-500/20">
                                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                        </svg>
                                    </div>

                                    <div class="flex flex-col gap-1 items-end">
                                        <span class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 border border-indigo-300 dark:border-indigo-700">
                                            {{ cls.subjects?.length || 0 }} subjects
                                        </span>
                                        <span
                                            v-if="cls.is_locked"
                                            class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-700"
                                        >
                                            🔒 Locked
                                        </span>
                                    </div>
                                </div>

                                <h3 class="text-lg sm:text-xl font-bold text-indigo-900 dark:text-indigo-100 mb-1 truncate">
                                    {{ cls.class_name }}
                                </h3>
                                <p class="text-sm font-semibold text-purple-600 dark:text-purple-400 mb-1">
                                    Section: {{ cls.section || 'N/A' }}
                                </p>

                                <p v-if="isAdmin" class="text-xs text-indigo-500 dark:text-indigo-400 mb-3 flex items-center gap-1 font-medium">
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
                                        class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 border border-indigo-100 dark:border-indigo-800"
                                    >
                                        {{ subject }}
                                    </span>
                                    <span
                                        v-if="cls.subjects.length > 3"
                                        class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-purple-50 dark:bg-purple-900/40 text-purple-600 dark:text-purple-300 border border-purple-100 dark:border-purple-800"
                                    >
                                        +{{ cls.subjects.length - 3 }} more
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pt-3 sm:pt-4 border-t border-indigo-100 dark:border-indigo-800">
                                    <span class="text-xs text-indigo-500 dark:text-indigo-400 flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                        {{ cls.student_count }} students
                                    </span>
                                    <span class="text-xs font-semibold text-purple-600 dark:text-purple-400 group-hover:text-purple-800 dark:group-hover:text-purple-200 transition-colors">
                                        Enter Results →
                                    </span>
                                </div>
                            </div>
                        </button>
                    </div>

                    <!-- Class Pagination -->
                    <div v-if="classes.links && classes.links.length > 3" class="mt-4 sm:mt-6 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/60 dark:border-indigo-800/60 shadow-sm px-3 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-center justify-between gap-3 transition-colors duration-300">
                        <div class="text-xs sm:text-sm text-indigo-600 dark:text-indigo-400 text-center sm:text-left">
                            Showing <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ classes.from }}</span> to
                            <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ classes.to }}</span> of
                            <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ classes.total }}</span> classes
                        </div>
                        <div class="flex items-center gap-1 flex-wrap justify-center">
                            <template v-for="(link, index) in classes.links" :key="index">
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
                                                ? 'bg-gradient-to-r from-indigo-500 to-purple-500 text-white shadow-md shadow-indigo-500/25'
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

            <!-- VIEW 2: MARKS INPUT -->
            <template v-else>
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
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg shadow-indigo-500/25 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-lg sm:text-2xl font-bold bg-gradient-to-r from-indigo-800 to-purple-800 dark:from-indigo-300 dark:to-purple-300 bg-clip-text text-transparent truncate">
                                    {{ classInfo.class_name }} — {{ classInfo.section }}
                                </h1>
                                <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400 mt-0.5">
                                    {{ subjects.length }} subjects · {{ students.total || 0 }} students
                                </p>
                            </div>
                        </div>

                        <!-- ✅ Fixed Dropdown Colors -->
                        <div class="flex items-center gap-2 bg-white dark:bg-slate-800 rounded-xl border border-indigo-200 dark:border-indigo-700 px-3 sm:px-4 py-2 shadow-sm w-full sm:w-auto">
                            <svg class="w-5 h-5 text-indigo-500 dark:text-indigo-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <select
                                v-model="selectedExam"
                                @change="handleExamChange"
                                class="text-sm font-medium text-indigo-800 dark:text-indigo-200 bg-transparent outline-none cursor-pointer w-full"
                            >
                                <option
                                    v-for="et in examTypesComputed"
                                    :key="et.value"
                                    :value="et.value"
                                    class="bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                                >
                                    {{ et.label }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Student Search -->
                <div class="mb-4 sm:mb-6 bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/60 dark:border-indigo-800/60 shadow-sm p-3 sm:p-4 transition-colors duration-300">
                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                        <div class="sm:col-span-7 relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-indigo-400 dark:text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="studentSearch"
                                type="text"
                                placeholder="Search student name or ID..."
                                class="w-full pl-10 pr-4 py-2 sm:py-2.5 border-2 border-indigo-200 dark:border-indigo-700 rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 bg-white dark:bg-slate-800 text-sm text-indigo-900 dark:text-indigo-100 placeholder:text-slate-400 dark:placeholder:text-slate-500"
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <input
                                v-model="rollNumber"
                                type="text"
                                placeholder="Roll number..."
                                class="w-full px-4 py-2 sm:py-2.5 border-2 border-indigo-200 dark:border-indigo-700 rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 bg-white dark:bg-slate-800 text-sm text-indigo-900 dark:text-indigo-100 placeholder:text-slate-400 dark:placeholder:text-slate-500"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <button
                                @click="resetStudentSearch"
                                :disabled="!studentSearch && !rollNumber"
                                class="w-full px-4 py-2 sm:py-2.5 text-sm font-medium text-rose-600 dark:text-rose-400 border-2 border-rose-200 dark:border-rose-800 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            >
                                ✕ Reset
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-4 sm:mb-6">
                    <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-indigo-200/50 dark:border-indigo-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-indigo-400 dark:text-indigo-500 uppercase tracking-wider">Total</p>
                        <p class="text-xl sm:text-2xl font-bold text-indigo-800 dark:text-indigo-200">{{ classSummary.totalCount }}</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-emerald-200/50 dark:border-emerald-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-emerald-500 dark:text-emerald-400 uppercase tracking-wider">Passed</p>
                        <p class="text-xl sm:text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ classSummary.passCount }}</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl p-3 sm:p-4 border border-rose-200/50 dark:border-rose-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-rose-500 dark:text-rose-400 uppercase tracking-wider">Failed</p>
                        <p class="text-xl sm:text-2xl font-bold text-rose-600 dark:text-rose-400">{{ classSummary.failCount }}</p>
                    </div>
                    <div class="bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-950/40 dark:to-purple-950/40 rounded-xl p-3 sm:p-4 border border-indigo-200/50 dark:border-indigo-800/60 shadow-sm transition-colors duration-300">
                        <p class="text-[10px] sm:text-xs font-medium text-indigo-400 dark:text-indigo-500 uppercase tracking-wider">Avg GPA</p>
                        <p class="text-xl sm:text-2xl font-bold text-indigo-700 dark:text-indigo-300">{{ classSummary.averageGpa }}</p>
                    </div>
                </div>

                <!-- Grading System -->
                <div class="mb-4 sm:mb-6 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-950/40 dark:to-purple-950/40 rounded-2xl border border-indigo-200/50 dark:border-indigo-800/60 p-3 sm:p-4 transition-colors duration-300">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h3 class="text-xs font-bold text-indigo-700 dark:text-indigo-300 uppercase tracking-wider">Grading System</h3>
                    </div>
                    <div class="grid grid-cols-3 sm:grid-cols-7 gap-2">
                        <div class="text-center p-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/40 border border-emerald-200 dark:border-emerald-800">
                            <p class="text-xs font-bold text-emerald-700 dark:text-emerald-300">A+</p>
                            <p class="text-[9px] text-emerald-600 dark:text-emerald-400">80-100</p>
                            <p class="text-[9px] font-bold text-emerald-700 dark:text-emerald-300">5.00</p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-green-50 dark:bg-green-900/40 border border-green-200 dark:border-green-800">
                            <p class="text-xs font-bold text-green-700 dark:text-green-300">A</p>
                            <p class="text-[9px] text-green-600 dark:text-green-400">70-79</p>
                            <p class="text-[9px] font-bold text-green-700 dark:text-green-300">4.00</p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-lime-50 dark:bg-lime-900/40 border border-lime-200 dark:border-lime-800">
                            <p class="text-xs font-bold text-lime-700 dark:text-lime-300">A-</p>
                            <p class="text-[9px] text-lime-600 dark:text-lime-400">60-69</p>
                            <p class="text-[9px] font-bold text-lime-700 dark:text-lime-300">3.50</p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-yellow-50 dark:bg-yellow-900/40 border border-yellow-200 dark:border-yellow-800">
                            <p class="text-xs font-bold text-yellow-700 dark:text-yellow-300">B</p>
                            <p class="text-[9px] text-yellow-600 dark:text-yellow-400">50-59</p>
                            <p class="text-[9px] font-bold text-yellow-700 dark:text-yellow-300">3.00</p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-orange-50 dark:bg-orange-900/40 border border-orange-200 dark:border-orange-800">
                            <p class="text-xs font-bold text-orange-700 dark:text-orange-300">C</p>
                            <p class="text-[9px] text-orange-600 dark:text-orange-400">40-49</p>
                            <p class="text-[9px] font-bold text-orange-700 dark:text-orange-300">2.00</p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-rose-50 dark:bg-rose-900/40 border border-rose-200 dark:border-rose-800">
                            <p class="text-xs font-bold text-rose-700 dark:text-rose-300">D</p>
                            <p class="text-[9px] text-rose-600 dark:text-rose-400">33-39</p>
                            <p class="text-[9px] font-bold text-rose-700 dark:text-rose-300">1.00</p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-red-50 dark:bg-red-900/40 border border-red-200 dark:border-red-800">
                            <p class="text-xs font-bold text-red-700 dark:text-red-300">F</p>
                            <p class="text-[9px] text-red-600 dark:text-red-400">0-32</p>
                            <p class="text-[9px] font-bold text-red-700 dark:text-red-300">0.00</p>
                        </div>
                    </div>
                </div>

                <!-- Marks Entry Table -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/50 dark:border-indigo-800/60 shadow-sm overflow-hidden transition-colors duration-300">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3 sm:p-4 border-b border-indigo-100 dark:border-indigo-800 bg-indigo-50/30 dark:bg-indigo-950/30">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold text-indigo-800 dark:text-indigo-200">Marks Entry</span>
                            <span class="text-xs bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 px-2 py-0.5 rounded-full">{{ studentsList.length }} students</span>

                            <span
                                v-if="hasLockedSubject && !hasEditableSubject"
                                class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-700"
                            >
                                🔒 All Locked
                            </span>
                            <span
                                v-else-if="hasLockedSubject"
                                class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300 border border-orange-300 dark:border-orange-700"
                            >
                                ⚠️ Partial
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 px-2 sm:px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border border-green-300 dark:border-green-700"
                            >
                                ✏️ Editable
                            </span>
                        </div>

                        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                            <button
                                @click="resetMarks"
                                :disabled="!hasEditableSubject"
                                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 rounded-lg border border-rose-200 dark:border-rose-800 bg-white dark:bg-slate-800 px-3 sm:px-3.5 py-1.5 text-xs font-medium text-rose-700 dark:text-rose-300 transition hover:bg-rose-50 dark:hover:bg-rose-950/40 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                🔄 Reset All
                            </button>

                            <button
                                v-if="hasEditableSubject"
                                @click="saveResults"
                                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 rounded-lg bg-gradient-to-r from-indigo-500 to-purple-500 px-3 sm:px-4 py-1.5 text-xs font-semibold text-white transition hover:from-indigo-600 hover:to-purple-600 shadow-sm"
                            >
                                💾 Save Results
                            </button>
                        </div>
                    </div>

                    <div v-if="studentsList.length === 0" class="p-8 sm:p-12 text-center">
                        <svg class="w-12 h-12 sm:w-16 sm:h-16 text-indigo-300 dark:text-indigo-700 mx-auto mb-3 sm:mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <h3 class="text-base sm:text-lg font-bold text-indigo-800 dark:text-indigo-200 mb-1">No Students Found</h3>
                        <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400">This class has no active students.</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[700px]">
                            <thead class="bg-indigo-50/50 dark:bg-indigo-950/30 border-b-2 border-indigo-100 dark:border-indigo-800">
                                <tr>
                                    <!-- ✅ z-20 added for mobile sticky fix -->
                                    <th class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 text-left text-[10px] sm:text-xs font-bold text-indigo-700 dark:text-indigo-300 uppercase tracking-wider sticky left-0 z-20 bg-indigo-50 dark:bg-slate-800 backdrop-blur-sm min-w-[180px] sm:min-w-[220px]">
                                        Student
                                    </th>
                                    <th
                                        v-for="subject in subjects"
                                        :key="subject"
                                        class="px-1.5 sm:px-2 py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wider min-w-[90px] sm:min-w-[100px]"
                                        :class="isSubjectLocked(subject) ? 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300' : 'text-indigo-600 dark:text-indigo-400'"
                                    >
                                        <div class="flex flex-col items-center gap-2">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-xs sm:text-sm">{{ subject }}</span>
                                                <span v-if="isSubjectLocked(subject)" class="text-xs">🔒</span>
                                            </div>
                                            <span class="text-[9px] sm:text-[10px] font-normal" :class="isSubjectLocked(subject) ? 'text-amber-500 dark:text-amber-400' : 'text-indigo-400 dark:text-indigo-500'">out of 100</span>

                                            <div class="flex items-center gap-1 mt-0.5">
                                                <button
                                                    v-if="!isSubjectLocked(subject)"
                                                    @click="lockSubject(subject)"
                                                    class="inline-flex items-center gap-1 rounded-md bg-amber-100 dark:bg-amber-900/40 hover:bg-amber-200 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-300 px-1.5 sm:px-2.5 py-1 text-[9px] sm:text-[10px] font-bold border border-amber-300 dark:border-amber-700 transition-colors"
                                                >
                                                    🔒 Lock
                                                </button>

                                                <template v-else>
                                                    <span class="text-[9px] sm:text-[10px] font-bold text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/40 px-1.5 sm:px-2.5 py-1 rounded-md border border-amber-300 dark:border-amber-700">
                                                        Locked
                                                    </span>
                                                    <button
                                                        v-if="isAdmin"
                                                        @click="unlockSubject(subject)"
                                                        class="inline-flex items-center gap-1 rounded-md bg-white dark:bg-slate-800 hover:bg-amber-50 dark:hover:bg-amber-950/40 text-amber-700 dark:text-amber-300 px-1.5 sm:px-2.5 py-1 text-[9px] sm:text-[10px] font-bold border border-amber-300 dark:border-amber-700 transition-colors"
                                                    >
                                                        🔓
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </th>
                                    <th class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold text-teal-600 dark:text-teal-400 uppercase tracking-wider min-w-[100px] sm:min-w-[120px] bg-teal-50/50 dark:bg-teal-950/30">
                                        <div class="flex flex-col items-center gap-1">
                                            <span>Total</span>
                                            <span class="text-[9px] sm:text-[10px] font-normal text-teal-500 dark:text-teal-400">marks</span>
                                        </div>
                                    </th>
                                    <th class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider min-w-[90px] sm:min-w-[110px] bg-amber-50/50 dark:bg-amber-950/30">
                                        <div class="flex flex-col items-center gap-1">
                                            <span>Position</span>
                                            <span class="text-[9px] sm:text-[10px] font-normal text-amber-500 dark:text-amber-400">rank</span>
                                        </div>
                                    </th>
                                    <th class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider min-w-[100px] sm:min-w-[130px] bg-purple-50/50 dark:bg-purple-950/30">
                                        Overall
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-indigo-50 dark:divide-slate-800">
                                <tr
                                    v-for="(student, index) in studentsList"
                                    :key="student.id"
                                    class="hover:bg-indigo-50/30 dark:hover:bg-indigo-950/20 transition"
                                >
                                    <!-- ✅ z-20 added for mobile sticky fix -->
                                    <td class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 sticky left-0 z-20 bg-white dark:bg-slate-900">
                                        <div class="flex items-center gap-2 sm:gap-3">
                                            <span class="text-[10px] sm:text-xs text-indigo-400 dark:text-indigo-500 font-medium w-5">{{ (students.from || 0) + index }}.</span>
                                            <div class="h-9 w-9 sm:h-10 sm:w-10 rounded-full bg-gradient-to-br from-indigo-400 to-purple-500 flex items-center justify-center overflow-hidden shadow-sm flex-shrink-0 ring-2 ring-white dark:ring-slate-800">
                                                <img
                                                    v-if="student.student_photo"
                                                    :src="'/storage/' + student.student_photo"
                                                    :alt="student.student_name"
                                                    class="w-full h-full object-cover"
                                                />
                                                <span v-else class="text-[10px] sm:text-xs font-bold text-white">
                                                    {{ student.student_name?.charAt(0)?.toUpperCase() }}
                                                </span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs sm:text-sm font-bold text-indigo-900 dark:text-indigo-100 truncate">{{ student.student_name }}</p>
                                                <p class="text-[10px] sm:text-[11px] text-indigo-400 dark:text-indigo-500">Roll: {{ student.roll_number || '-' }}</p>
                                            </div>
                                        </div>
                                    </td>

                                    <td
                                        v-for="subject in subjects"
                                        :key="`${student.id}-${subject}`"
                                        class="px-1.5 sm:px-2 py-3 sm:py-4 text-center"
                                        :class="isSubjectLocked(subject) ? 'bg-amber-50/30 dark:bg-amber-950/20' : ''"
                                    >
                                        <div class="flex flex-col items-center gap-1.5 sm:gap-2">
                                            <input
                                                v-model="marksData[student.id][subject]"
                                                type="number"
                                                min="0"
                                                max="100"
                                                placeholder="—"
                                                :disabled="isSubjectLocked(subject) && !isAdmin"
                                                :class="[
                                                    'no-spinner w-16 sm:w-20 h-10 sm:h-11 rounded-xl border-2 px-2 sm:px-3 text-sm sm:text-base font-bold text-center outline-none transition-all duration-200 placeholder:text-slate-300 dark:placeholder:text-slate-600',
                                                    (isSubjectLocked(subject) && !isAdmin)
                                                        ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 text-slate-500 dark:text-slate-400 cursor-not-allowed'
                                                        : 'border-indigo-200 dark:border-indigo-700 bg-white dark:bg-slate-800 text-indigo-900 dark:text-indigo-100 shadow-sm focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 hover:border-indigo-300 dark:hover:border-indigo-600',
                                                ]"
                                            />
                                            <template v-if="marksData[student.id][subject] !== null && marksData[student.id][subject] !== ''">
                                                <span
                                                    :class="[
                                                        'inline-flex items-center gap-1 px-1.5 sm:px-2 py-0.5 text-[9px] sm:text-[10px] font-bold rounded-full border',
                                                        getGradeInfo(marksData[student.id][subject]).bg,
                                                        getGradeInfo(marksData[student.id][subject]).color,
                                                        getGradeInfo(marksData[student.id][subject]).border,
                                                    ]"
                                                >
                                                    {{ getGradeInfo(marksData[student.id][subject]).letter }}
                                                    · {{ getGradeInfo(marksData[student.id][subject]).gpa.toFixed(2) }}
                                                </span>
                                            </template>
                                            <template v-else>
                                                <span class="text-[10px] text-slate-300 dark:text-slate-600 font-medium">-</span>
                                            </template>
                                        </div>
                                    </td>

                                    <td class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 text-center bg-teal-50/30 dark:bg-teal-950/20">
                                        <div class="flex flex-col items-center gap-1">
                                            <span class="inline-flex items-center justify-center px-2 sm:px-3 py-1.5 text-sm sm:text-base font-bold rounded-lg border-2 border-teal-300 dark:border-teal-800 bg-white dark:bg-slate-800 text-teal-800 dark:text-teal-300 min-w-[50px] sm:min-w-[60px]">
                                                {{ getStudentTotalMarks(student.id) }}
                                            </span>
                                            <span class="text-[9px] sm:text-[10px] text-teal-600 dark:text-teal-400 font-medium">
                                                {{ getStudentTotalSubjects(student.id) }} subjects
                                            </span>
                                        </div>
                                    </td>

                                    <td class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 text-center bg-amber-50/30 dark:bg-amber-950/20">
                                        <div class="flex flex-col items-center gap-1">
                                            <span class="inline-flex items-center justify-center px-2 sm:px-3 py-1.5 text-sm sm:text-base font-bold rounded-lg border-2 border-amber-300 dark:border-amber-800 bg-white dark:bg-slate-800 text-amber-800 dark:text-amber-300 min-w-[50px] sm:min-w-[60px]">
                                                {{ getOrdinal(getStudentRank(student.id)) }}
                                            </span>
                                            <span class="text-[9px] sm:text-[10px] text-amber-600 dark:text-amber-400 font-medium">of {{ students.total || 0 }}</span>
                                        </div>
                                    </td>

                                    <td class="px-2 sm:px-3 lg:px-4 py-3 sm:py-4 text-center bg-purple-50/30 dark:bg-purple-950/20">
                                        <div class="flex flex-col items-center gap-1.5 sm:gap-2">
                                            <span
                                                :class="[
                                                    'inline-flex items-center justify-center px-2 sm:px-3 py-1.5 text-xs sm:text-sm font-bold rounded-lg border-2 min-w-[45px] sm:min-w-[50px]',
                                                    getStudentOverall(student.id).hasFail
                                                        ? 'bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-300 border-red-300 dark:border-red-800'
                                                        : 'bg-gradient-to-r from-purple-50 to-indigo-50 dark:from-purple-950/40 dark:to-indigo-950/40 text-purple-800 dark:text-purple-300 border-purple-300 dark:border-purple-800',
                                                ]"
                                            >
                                                {{ getStudentOverall(student.id).letter }}
                                            </span>
                                            <span
                                                :class="[
                                                    'text-xs sm:text-sm font-bold',
                                                    getStudentOverall(student.id).hasFail ? 'text-red-700 dark:text-red-300' : 'text-purple-700 dark:text-purple-300',
                                                ]"
                                            >
                                                {{ getStudentOverall(student.id).gpa }}
                                            </span>

                                            <button
                                                @click="printStudent(student)"
                                                class="inline-flex items-center gap-1 rounded-md bg-indigo-100 dark:bg-indigo-900/40 hover:bg-indigo-200 dark:hover:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 px-1.5 sm:px-2.5 py-1 text-[9px] sm:text-[10px] font-bold border border-indigo-300 dark:border-indigo-700 transition-colors"
                                            >
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                                </svg>
                                                Print
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Student Pagination -->
                    <div v-if="students.links && students.links.length > 3" class="px-3 sm:px-6 py-3 sm:py-4 border-t border-indigo-100 dark:border-indigo-800 bg-indigo-50/30 dark:bg-indigo-950/30 flex flex-col sm:flex-row items-center justify-between gap-3">
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
                                                ? 'bg-gradient-to-r from-indigo-500 to-purple-500 text-white shadow-md shadow-indigo-500/25'
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
        </div>

        <!-- ==================== PRINTABLE AREA ==================== -->
        <div
            v-if="printingStudent"
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
                        {{ school.name.toUpperCase() }}
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
                                {{ getExamTypeLabel(selectedExam).toUpperCase() }}
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
                        <span style="font-weight: 800; color: #064e3b; text-transform: uppercase;">{{ getExamTypeLabel(selectedExam) }}</span>
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
                            <tr
                                v-for="(subject, idx) in subjects"
                                :key="subject"
                                :style="idx % 2 === 0 ? 'background: #ffffff;' : 'background: #f0fdfa;'"
                            >
                                <td style="padding: 7px 12px; font-weight: 700; color: #064e3b; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">{{ subject }}</td>
                                <td style="padding: 7px 12px; text-align: center; color: #475569; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">100</td>
                                <td style="padding: 7px 12px; text-align: center; font-weight: 800; color: #047857; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">
                                    {{ marksData[printingStudent.id]?.[subject] ?? '—' }}
                                </td>
                                <td style="padding: 7px 12px; text-align: center; color: #475569; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;">
                                    {{ getSubjectHighest(subject) }}
                                </td>
                                <td style="padding: 7px 12px; text-align: center; font-weight: 800; border-bottom: 1px solid #d1fae5; border-right: 1px solid #d1fae5;"
                                    :style="getGradeInfo(marksData[printingStudent.id]?.[subject]).letter === 'F' ? 'color: #b91c1c; background: #fee2e2;' : 'color: #047857;'">
                                    {{ getGradeInfo(marksData[printingStudent.id]?.[subject]).letter }}
                                </td>
                                <td style="padding: 7px 12px; text-align: center; font-weight: 800; border-bottom: 1px solid #d1fae5;"
                                    :style="getGradeInfo(marksData[printingStudent.id]?.[subject]).letter === 'F' ? 'color: #b91c1c; background: #fee2e2;' : 'color: #0f766e;'">
                                    {{ getGradeInfo(marksData[printingStudent.id]?.[subject]).gpa === '-' ? '—' : getGradeInfo(marksData[printingStudent.id]?.[subject]).gpa.toFixed(2) }}
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
                                    {{ getStudentTotalMarks(printingStudent.id) }}
                                </p>
                                <p style="font-size: 9px; color: #0f766e; margin: 4px 0 0; font-weight: 600;">
                                    of {{ subjects.length * 100 }}
                                </p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center; background: linear-gradient(180deg, #fffbeb 0%, #fef3c7 100%); border-right: 1px solid #a7f3d0;">
                                <p style="font-size: 9px; font-weight: 800; color: #92400e; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;">Position</p>
                                <p style="font-size: 26px; font-weight: 900; color: #92400e; margin: 0; line-height: 1;">
                                    {{ getOrdinal(getStudentRank(printingStudent.id)) }}
                                </p>
                                <p style="font-size: 9px; color: #b45309; margin: 4px 0 0; font-weight: 600;">
                                    out of {{ students.total || 0 }}
                                </p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center; background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%); border-right: 1px solid #a7f3d0;">
                                <p style="font-size: 9px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;">Overall GPA</p>
                                <p style="font-size: 26px; font-weight: 900; color: #047857; margin: 0; line-height: 1;">{{ getStudentOverall(printingStudent.id).gpa }}</p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center; background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%); border-right: 1px solid #a7f3d0;">
                                <p style="font-size: 9px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;">Letter Grade</p>
                                <p style="font-size: 26px; font-weight: 900; margin: 0; line-height: 1;"
                                   :style="getStudentOverall(printingStudent.id).hasFail ? 'color: #b91c1c;' : 'color: #047857;'">
                                    {{ getStudentOverall(printingStudent.id).letter }}
                                </p>
                            </td>
                            <td style="width: 20%; padding: 14px 8px; text-align: center;"
                                :style="getStudentOverall(printingStudent.id).hasFail ? 'background: linear-gradient(180deg, #fef2f2 0%, #fee2e2 100%);' : 'background: linear-gradient(180deg, #ecfdf5 0%, #bbf7d0 100%);'">
                                <p style="font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; margin: 0 0 4px;"
                                   :style="getStudentOverall(printingStudent.id).hasFail ? 'color: #b91c1c;' : 'color: #047857;'">
                                    Result
                                </p>
                                <p style="font-size: 18px; font-weight: 900; margin: 0; line-height: 1; letter-spacing: 0.05em;"
                                   :style="getStudentOverall(printingStudent.id).hasFail ? 'color: #b91c1c;' : 'color: #047857;'">
                                    {{ getStudentOverall(printingStudent.id).hasFail ? 'FAILED' : 'PASSED' }}
                                </p>
                                <p style="font-size: 10px; font-weight: 700; margin: 5px 0 0;"
                                   :style="getStudentOverall(printingStudent.id).hasFail ? 'color: #b91c1c;' : 'color: #0f766e;'">
                                    ({{ getStudentTotalMarks(printingStudent.id) }} Marks)
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