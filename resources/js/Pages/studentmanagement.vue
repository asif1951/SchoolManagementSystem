<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import QrcodeVue from 'qrcode.vue'

const props = defineProps({
    students: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    classes: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ total: 0, active: 0, inactive: 0 }) },
    filters: {
        type: Object,
        default: () => ({ search: '', class: '', section: '', status: '', academic_year: '' }),
    },
    availableYears: { type: Array, default: () => [] },
    selectedYear: { type: String, default: '' },
    flash: { type: Object, default: () => ({}) },
})

// ============================================================
// ✅ Shared page props (school info + logo)
// ============================================================
const page = usePage()

const schoolInfo = computed(() => page.props.school ?? {})
const schoolName = computed(() => schoolInfo.value.name || 'Your School')
const schoolMotto = computed(() => schoolInfo.value.motto || 'Excellence in Education')
const schoolAddress = computed(() => schoolInfo.value.address || '')

// ✅ School logo URL helper
const schoolLogo = computed(() => {
    const logo = schoolInfo.value.logo
    if (!logo) return null
    if (logo.startsWith('http')) return logo
    return `/storage/${logo.replace(/^\/+/, '')}`
})

// ✅ If the logo image fails to load, fall back to the book icon
const logoError = ref(false)

// ============================================================
// Toast
// ============================================================
const toast = ref({ show: false, message: '', type: 'success' })
let toastTimeout = null

const showToast = (message, type = 'success') => {
    if (toastTimeout) clearTimeout(toastTimeout)
    toast.value = { show: true, message, type }
    toastTimeout = setTimeout(() => { toast.value.show = false }, 5000)
}

if (props.flash?.success) showToast(props.flash.success, 'success')
if (props.flash?.error) showToast(props.flash.error, 'error')

// ============================================================
// Year State
// ============================================================
const selectedYear = ref(
    String(props.selectedYear || props.filters?.academic_year || new Date().getFullYear())
)
const yearInput = ref(String(selectedYear.value))

// ============================================================
// Filter State
// ============================================================
const search = ref(props.filters.search || '')
const selectedClass = ref(props.filters.class || '')
const selectedSection = ref(props.filters.section || '')
const selectedStatus = ref(props.filters.status || '')

// ============================================================
// Modal State
// ============================================================
const showDeleteModal = ref(false)
const studentToDelete = ref(null)

const showIdCardModal = ref(false)
const selectedStudent = ref(null)

const showEditModal = ref(false)
const editingStudent = ref(null)
const editSameAsPresent = ref(false)

const editForm = useForm({
    studentName: '',
    dateOfBirth: '',
    gender: '',
    bloodGroup: '',
    studentPhoto: null,
    academicYear: '',
    class: '',
    section: '',
    rollNumber: '',
    admissionDate: '',
    status: 'active',
    fatherName: '',
    fatherPhone: '',
    motherName: '',
    motherPhone: '',
    guardianName: '',
    guardianPhone: '',
    guardianRelation: '',
    presentAddress: '',
    permanentAddress: '',
})

// ============================================================
// Computed
// ============================================================
const studentsList = computed(() => props.students?.data || [])

const availableClasses = computed(() => {
    return [...new Set(props.classes.map(item => item.class_name).filter(Boolean))]
})

const availableSections = computed(() => {
    if (!selectedClass.value) {
        return [...new Set(props.classes.map(item => item.section).filter(Boolean))]
    }
    return [...new Set(
        props.classes
            .filter(item => item.class_name === selectedClass.value)
            .map(item => item.section)
            .filter(Boolean)
    )]
})

const editAvailableSections = computed(() => {
    if (!editForm.class) return []
    return [...new Set(
        props.classes
            .filter(item => item.class_name === editForm.class)
            .map(item => item.section)
            .filter(Boolean)
    )]
})

const qrValue = computed(() => {
    if (!selectedStudent.value) return ''
    return selectedStudent.value.student_id || String(selectedStudent.value.id)
})

// ✅ ID card phone — fallback chain
const idCardPhone = computed(() => {
    const s = selectedStudent.value
    if (!s) return '—'
    return s.father_phone || s.guardian_phone || s.mother_phone || '—'
})

const hasActiveFilters = computed(() => {
    return search.value || selectedClass.value || selectedSection.value || selectedStatus.value
})

// ============================================================
// Year Handlers
// ============================================================
const applyYear = () => {
    const year = String(yearInput.value).trim()
    if (!/^\d{4}$/.test(year)) {
        yearInput.value = selectedYear.value
        return
    }
    const numYear = parseInt(year)
    if (numYear < 2000 || numYear > 2100) {
        yearInput.value = selectedYear.value
        return
    }
    if (year === selectedYear.value) return
    selectedYear.value = year
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

watch(() => props.selectedYear, (newYear) => {
    if (newYear && String(newYear) !== String(selectedYear.value)) {
        selectedYear.value = String(newYear)
        yearInput.value = String(newYear)
    }
})

watch(() => props.filters?.academic_year, (newYear) => {
    if (newYear && String(newYear) !== String(selectedYear.value)) {
        selectedYear.value = String(newYear)
        yearInput.value = String(newYear)
    }
})

const handleYearChange = () => {
    router.get(
        route('studentmanagement'),
        {
            academic_year: selectedYear.value,
            search: search.value,
            class: selectedClass.value,
            section: selectedSection.value,
            status: selectedStatus.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    )
}

// ============================================================
// Methods
// ============================================================
const fetchStudents = () => {
    router.get(
        route('studentmanagement'),
        {
            academic_year: selectedYear.value,
            search: search.value,
            class: selectedClass.value,
            section: selectedSection.value,
            status: selectedStatus.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    )
}

const resetFilters = () => {
    search.value = ''
    selectedClass.value = ''
    selectedSection.value = ''
    selectedStatus.value = ''
    fetchStudents()
}

const confirmDelete = (student) => {
    studentToDelete.value = student
    showDeleteModal.value = true
}

const deleteStudent = () => {
    if (!studentToDelete.value) return
    router.delete(route('studentmanagement.destroy', studentToDelete.value.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            showDeleteModal.value = false
            studentToDelete.value = null
            if (page.props.flash?.success) {
                showToast(page.props.flash.success, 'success')
            }
        },
    })
}

const showIdCard = (student) => {
    selectedStudent.value = student
    showIdCardModal.value = true
}

const closeIdCard = () => {
    showIdCardModal.value = false
    selectedStudent.value = null
}

// ============================================================
// ✅ Print ID Card (front + back on one A4 sheet, real size 54 × 86 mm)
// - A temporary class on <body> scopes the print CSS to this action only
// - A temporary @page rule removes browser header/footer and sets A4
// ============================================================
const printIdCard = () => {
    const pageStyle = document.createElement('style')
    pageStyle.id = 'id-card-print-page-style'
    pageStyle.textContent = '@page { size: A4 portrait; margin: 0; }'
    document.head.appendChild(pageStyle)
    document.body.classList.add('printing-id-card')

    const cleanup = () => {
        document.body.classList.remove('printing-id-card')
        pageStyle.remove()
        window.removeEventListener('afterprint', cleanup)
    }
    window.addEventListener('afterprint', cleanup)

    // small delay so the print styles are applied before the dialog opens
    setTimeout(() => window.print(), 100)
}

const openEditModal = (student) => {
    editingStudent.value = student
    editForm.studentName = student.student_name || ''
    editForm.dateOfBirth = student.date_of_birth || ''
    editForm.gender = student.gender || ''
    editForm.bloodGroup = student.blood_group || ''
    editForm.studentPhoto = null
    editForm.academicYear = student.academic_year || ''
    editForm.class = student.class || ''
    editForm.section = student.section || ''
    editForm.rollNumber = student.roll_number || ''
    editForm.admissionDate = student.admission_date || ''
    editForm.status = student.status || 'active'
    editForm.fatherName = student.father_name || ''
    editForm.fatherPhone = student.father_phone || ''
    editForm.motherName = student.mother_name || ''
    editForm.motherPhone = student.mother_phone || ''
    editForm.guardianName = student.guardian_name || ''
    editForm.guardianPhone = student.guardian_phone || ''
    editForm.guardianRelation = student.guardian_relation || ''
    editForm.presentAddress = student.present_address || ''
    editForm.permanentAddress = student.permanent_address || ''

    editSameAsPresent.value = !!(
        student.present_address &&
        student.permanent_address &&
        student.present_address === student.permanent_address
    )

    editForm.clearErrors()
    showEditModal.value = true
}

const closeEditModal = () => {
    showEditModal.value = false
    editingStudent.value = null
    editSameAsPresent.value = false
    editForm.reset()
    editForm.clearErrors()
}

const handleEditClassChange = () => {
    editForm.section = ''
}

const handleEditPhotoChange = (event) => {
    editForm.studentPhoto = event.target.files[0] ?? null
}

const editCopyPresentToPermanent = () => {
    if (editSameAsPresent.value) {
        editForm.permanentAddress = editForm.presentAddress
    }
}

const submitEditForm = () => {
    editForm
        .transform((data) => ({ ...data, _method: 'PUT' }))
        .post(route('studentmanagement.update.post', editingStudent.value.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                showEditModal.value = false
                editingStudent.value = null
                editSameAsPresent.value = false
                editForm.reset()
                if (page.props.flash?.success) {
                    showToast(page.props.flash.success, 'success')
                }
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0]
                showToast(firstError || 'Please fix the errors.', 'error')
            },
        })
}

const getStatusBadgeClass = (status) => {
    return status === 'active'
        ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 ring-emerald-200 dark:ring-emerald-800'
        : 'bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 ring-rose-200 dark:ring-rose-800'
}

const getStatusDotClass = (status) => {
    return status === 'active' ? 'bg-emerald-500' : 'bg-rose-500'
}

// ============================================================
// Watchers
// ============================================================
let searchTimeout = null

watch([search, selectedClass, selectedSection, selectedStatus], () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        fetchStudents()
    }, 500)
})

watch(
    () => editForm.presentAddress,
    (newValue) => {
        if (editSameAsPresent.value) {
            editForm.permanentAddress = newValue
        }
    }
)

onBeforeUnmount(() => {
    if (searchTimeout) clearTimeout(searchTimeout)
    if (toastTimeout) clearTimeout(toastTimeout)
})
</script>

<template>
    <Head title="Student Management" />

    <AuthenticatedLayout>
        <div class="w-full min-h-screen bg-gradient-to-br from-slate-50 via-blue-50/30 to-indigo-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 transition-colors duration-300">
            <div class="mx-auto max-w-[1600px] px-2.5 xs:px-3 sm:px-4 md:px-6 lg:px-8 py-3 xs:py-4 sm:py-6 lg:py-8 space-y-4 sm:space-y-6">

                <!-- TOAST -->
                <Teleport to="body">
                    <div
                        v-if="toast.show"
                        :class="[
                            'fixed top-3 right-3 left-3 xs:left-auto xs:max-w-sm xs:w-full p-3 sm:p-4 rounded-xl shadow-2xl transition-all duration-500 ease-in-out',
                            toast.type === 'success' ? 'bg-green-50 dark:bg-green-900/30 border-2 border-green-500 dark:border-green-600' : '',
                            toast.type === 'error' ? 'bg-red-50 dark:bg-red-900/30 border-2 border-red-500 dark:border-red-600' : '',
                            toast.type === 'warning' ? 'bg-yellow-50 dark:bg-yellow-900/30 border-2 border-yellow-500 dark:border-yellow-600' : '',
                            toast.type === 'info' ? 'bg-blue-50 dark:bg-blue-900/30 border-2 border-blue-500 dark:border-blue-600' : '',
                        ]"
                        style="animation: slideInRight 0.5s ease-out; z-index: 999999 !important;"
                    >
                        <div class="flex items-start gap-2.5 sm:gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                <svg v-if="toast.type === 'success'" class="w-4 h-4 sm:w-5 sm:h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <svg v-else-if="toast.type === 'error'" class="w-4 h-4 sm:w-5 sm:h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <svg v-else-if="toast.type === 'warning'" class="w-4 h-4 sm:w-5 sm:h-5 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <svg v-else class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p :class="[
                                    'text-xs sm:text-sm font-medium break-words',
                                    toast.type === 'success' ? 'text-green-800 dark:text-green-200' : '',
                                    toast.type === 'error' ? 'text-red-800 dark:text-red-200' : '',
                                    toast.type === 'warning' ? 'text-yellow-800 dark:text-yellow-200' : '',
                                    toast.type === 'info' ? 'text-blue-800 dark:text-blue-200' : '',
                                ]">{{ toast.message }}</p>
                            </div>
                            <button @click="toast.show = false" class="flex-shrink-0 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors p-0.5">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </Teleport>

                <!-- ==================== PAGE HEADER ==================== -->
                <div class="flex items-center gap-2.5 sm:gap-3 lg:gap-4">
                    <div class="relative flex-shrink-0">
                        <div class="absolute inset-0 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl sm:rounded-2xl blur-lg opacity-40"></div>
                        <div class="relative p-2 sm:p-2.5 lg:p-3 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl sm:rounded-2xl shadow-lg shadow-blue-500/30">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 lg:w-7 lg:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg xs:text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 dark:from-slate-100 dark:to-slate-300 bg-clip-text text-transparent truncate">
                            Student Management
                        </h1>
                        <p class="text-[11px] sm:text-xs lg:text-sm text-slate-500 dark:text-slate-400 flex items-center gap-1.5 sm:gap-2 mt-0.5 sm:mt-1">
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse flex-shrink-0"></span>
                            <span class="truncate">Showing students for <strong class="text-blue-600 dark:text-blue-400">{{ selectedYear }}</strong></span>
                        </p>
                    </div>
                </div>

                <!-- ==================== YEAR FILTER ==================== -->
                <div class="relative overflow-hidden rounded-xl sm:rounded-2xl border-2 border-indigo-200/60 dark:border-indigo-800/60 bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50/50 dark:from-indigo-950/40 dark:via-purple-950/40 dark:to-slate-900/40 shadow-md transition-colors duration-300">
                    <div class="absolute -top-12 -right-12 w-32 sm:w-40 h-32 sm:h-40 bg-indigo-300/20 dark:bg-indigo-700/20 rounded-full blur-3xl pointer-events-none"></div>
                    <div class="absolute -bottom-12 -left-12 w-32 sm:w-40 h-32 sm:h-40 bg-purple-300/20 dark:bg-purple-700/20 rounded-full blur-3xl pointer-events-none"></div>

                    <div class="relative p-3 xs:p-4 sm:p-5">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-4">
                            <div class="flex items-center gap-2.5 sm:gap-3">
                                <div class="p-1.5 sm:p-2 lg:p-2.5 rounded-lg sm:rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 shadow-lg shadow-indigo-500/30 flex-shrink-0">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xs sm:text-sm font-bold text-indigo-900 dark:text-indigo-200">Academic Year</h3>
                                    <p class="text-[10px] sm:text-xs text-indigo-600 dark:text-indigo-400">Type year or use buttons</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-1.5 sm:gap-2 w-full sm:w-auto justify-between sm:justify-end">
                                <div class="flex items-center gap-1.5 sm:gap-2">
                                    <button
                                        type="button"
                                        @click="decrementYear"
                                        class="group p-1.5 sm:p-2 lg:p-2.5 rounded-lg sm:rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm text-indigo-700 dark:text-indigo-300 shadow-sm hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-500 dark:hover:border-indigo-500 hover:shadow-md transition-all active:scale-90 flex-shrink-0"
                                        title="Previous year"
                                    >
                                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 transition-transform group-hover:-translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                        </svg>
                                    </button>

                                    <input
                                        v-model="yearInput"
                                        @keydown.enter.prevent="applyYear"
                                        @blur="applyYear"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="4"
                                        placeholder="YYYY"
                                        class="w-20 xs:w-24 sm:w-28 lg:w-32 rounded-lg sm:rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-slate-800 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-sm sm:text-base font-bold text-indigo-900 dark:text-indigo-100 text-center tracking-wider outline-none cursor-text transition-all focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/20 hover:border-indigo-400 shadow-sm"
                                    />

                                    <button
                                        type="button"
                                        @click="incrementYear"
                                        class="group p-1.5 sm:p-2 lg:p-2.5 rounded-lg sm:rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm text-indigo-700 dark:text-indigo-300 shadow-sm hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-500 dark:hover:border-indigo-500 hover:shadow-md transition-all active:scale-90 flex-shrink-0"
                                        title="Next year"
                                    >
                                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                        </svg>
                                    </button>
                                </div>

                                <span
                                    v-if="String(selectedYear) === String(new Date().getFullYear())"
                                    class="ml-1 sm:ml-2 px-2 sm:px-3 py-1 sm:py-1.5 rounded-full text-[9px] sm:text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700 shadow-sm flex-shrink-0 animate-pulse"
                                >
                                    NOW
                                </span>
                            </div>
                        </div>

                        <div v-if="availableYears.length > 0" class="mt-3 sm:mt-4 flex flex-wrap items-center gap-1.5 sm:gap-2 pt-3 border-t border-indigo-200/60 dark:border-indigo-800/60">
                            <span class="text-[10px] sm:text-xs font-bold text-indigo-700 dark:text-indigo-300 uppercase tracking-wider">Quick select:</span>
                            <button
                                v-for="year in availableYears.slice(0, 6)"
                                :key="year"
                                type="button"
                                @click="yearInput = String(year); applyYear()"
                                :class="[
                                    'px-2 sm:px-3 py-1 sm:py-1.5 rounded-lg text-[10px] sm:text-xs font-bold transition-all active:scale-95 shadow-sm',
                                    String(year) === String(selectedYear)
                                        ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-md shadow-indigo-500/30 ring-2 ring-indigo-300 dark:ring-indigo-700'
                                        : 'bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700 hover:bg-white dark:hover:bg-slate-800 hover:border-indigo-400 hover:shadow-md'
                                ]"
                            >
                                {{ year }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ==================== STATS ==================== -->
                <div class="grid grid-cols-1 xs:grid-cols-3 gap-2.5 sm:gap-3 lg:gap-4">
                    <div class="group relative overflow-hidden rounded-xl sm:rounded-2xl border border-blue-100 dark:border-blue-900/60 bg-white dark:bg-slate-900 shadow-sm hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                        <div class="absolute -top-8 -right-8 w-24 sm:w-32 h-24 sm:h-32 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-full blur-2xl opacity-10 group-hover:opacity-20 transition-opacity"></div>
                        <div class="relative p-3 xs:p-4 sm:p-5 lg:p-6">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[10px] xs:text-xs sm:text-sm font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wider truncate">Total Students</p>
                                    <p class="text-2xl xs:text-3xl sm:text-4xl font-extrabold bg-gradient-to-br from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 bg-clip-text text-transparent mt-1 tabular-nums">{{ stats.total }}</p>
                                    <p class="text-[9px] xs:text-[10px] sm:text-xs text-slate-400 dark:text-slate-500 mt-1">for {{ selectedYear }}</p>
                                </div>
                                <div class="p-2.5 xs:p-3 sm:p-4 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg sm:rounded-xl shadow-lg shadow-blue-500/30 flex-shrink-0 group-hover:scale-110 transition-transform duration-300">
                                    <svg class="w-4 h-4 xs:w-5 xs:h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="group relative overflow-hidden rounded-xl sm:rounded-2xl border border-emerald-100 dark:border-emerald-900/60 bg-white dark:bg-slate-900 shadow-sm hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                        <div class="absolute -top-8 -right-8 w-24 sm:w-32 h-24 sm:h-32 bg-gradient-to-br from-emerald-400 to-teal-500 rounded-full blur-2xl opacity-10 group-hover:opacity-20 transition-opacity"></div>
                        <div class="relative p-3 xs:p-4 sm:p-5 lg:p-6">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[10px] xs:text-xs sm:text-sm font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider truncate">Active</p>
                                    <p class="text-2xl xs:text-3xl sm:text-4xl font-extrabold bg-gradient-to-br from-emerald-600 to-teal-600 dark:from-emerald-400 dark:to-teal-400 bg-clip-text text-transparent mt-1 tabular-nums">{{ stats.active }}</p>
                                    <p class="text-[9px] xs:text-[10px] sm:text-xs text-slate-400 dark:text-slate-500 mt-1">currently enrolled</p>
                                </div>
                                <div class="p-2.5 xs:p-3 sm:p-4 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-lg sm:rounded-xl shadow-lg shadow-emerald-500/30 flex-shrink-0 group-hover:scale-110 transition-transform duration-300">
                                    <svg class="w-4 h-4 xs:w-5 xs:h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="group relative overflow-hidden rounded-xl sm:rounded-2xl border border-rose-100 dark:border-rose-900/60 bg-white dark:bg-slate-900 shadow-sm hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                        <div class="absolute -top-8 -right-8 w-24 sm:w-32 h-24 sm:h-32 bg-gradient-to-br from-rose-400 to-pink-500 rounded-full blur-2xl opacity-10 group-hover:opacity-20 transition-opacity"></div>
                        <div class="relative p-3 xs:p-4 sm:p-5 lg:p-6">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[10px] xs:text-xs sm:text-sm font-semibold text-rose-600 dark:text-rose-400 uppercase tracking-wider truncate">Inactive</p>
                                    <p class="text-2xl xs:text-3xl sm:text-4xl font-extrabold bg-gradient-to-br from-rose-600 to-pink-600 dark:from-rose-400 dark:to-pink-400 bg-clip-text text-transparent mt-1 tabular-nums">{{ stats.inactive }}</p>
                                    <p class="text-[9px] xs:text-[10px] sm:text-xs text-slate-400 dark:text-slate-500 mt-1">not enrolled</p>
                                </div>
                                <div class="p-2.5 xs:p-3 sm:p-4 bg-gradient-to-br from-rose-500 to-pink-600 rounded-lg sm:rounded-xl shadow-lg shadow-rose-500/30 flex-shrink-0 group-hover:scale-110 transition-transform duration-300">
                                    <svg class="w-4 h-4 xs:w-5 xs:h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== FILTERS ==================== -->
                <div class="rounded-xl sm:rounded-2xl border border-white/60 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl shadow-sm p-3 xs:p-4 sm:p-5 transition-colors duration-300">
                    <div class="flex items-center gap-2 mb-3 sm:mb-4">
                        <span class="flex h-6 w-6 sm:h-7 sm:w-7 items-center justify-center rounded-lg bg-gradient-to-br from-slate-700 to-slate-900 dark:from-slate-600 dark:to-slate-800 text-white shadow-md flex-shrink-0">
                            <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                            </svg>
                        </span>
                        <h3 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wider">Filters</h3>
                        <span v-if="hasActiveFilters" class="inline-flex items-center px-1.5 sm:px-2 py-0.5 rounded-full text-[9px] sm:text-[10px] font-bold bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 ring-1 ring-blue-200 dark:ring-blue-800">
                            Active
                        </span>
                    </div>

                    <div class="grid grid-cols-1 xs:grid-cols-2 sm:grid-cols-2 lg:grid-cols-5 gap-2.5 xs:gap-3 sm:gap-4">
                        <div class="xs:col-span-2 sm:col-span-2 lg:col-span-1">
                            <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Search</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 pl-2.5 sm:pl-3 flex items-center pointer-events-none">
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-slate-400 dark:text-slate-500 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="search"
                                    type="text"
                                    placeholder="Name, ID, phone..."
                                    class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-8 sm:pl-9 pr-3 sm:pr-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Class</label>
                            <select
                                v-model="selectedClass"
                                @change="selectedSection = ''"
                                class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 cursor-pointer"
                            >
                                <option value="">All Classes</option>
                                <option v-for="cls in availableClasses" :key="cls" :value="cls">{{ cls }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Section</label>
                            <select
                                v-model="selectedSection"
                                class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 cursor-pointer"
                            >
                                <option value="">All Sections</option>
                                <option v-for="section in availableSections" :key="section" :value="section">{{ section }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Status</label>
                            <select
                                v-model="selectedStatus"
                                class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 cursor-pointer"
                            >
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <button
                                @click="resetFilters"
                                :disabled="!hasActiveFilters"
                                class="group w-full px-3 sm:px-4 py-1.5 sm:py-2 lg:py-2.5 rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-800/80 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200 transition-all duration-300 hover:bg-white dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-md active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none"
                            >
                                <span class="inline-flex items-center gap-1.5 justify-center">
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 transition-transform group-hover:rotate-180 duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Reset
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ==================== STUDENTS TABLE ==================== -->
                <div class="rounded-xl sm:rounded-2xl border border-white/60 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xl shadow-slate-200/50 dark:shadow-black/40 overflow-hidden transition-colors duration-300">
                    <div class="flex items-center justify-between gap-2 px-3 xs:px-4 sm:px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-50 to-blue-50/50 dark:from-slate-800/50 dark:to-slate-800/30">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="flex h-7 w-7 sm:h-8 sm:w-8 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-md shadow-blue-500/30 flex-shrink-0">
                                <svg class="h-3.5 w-3.5 sm:h-4 sm:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-200">All Students</h3>
                                <p class="text-[9px] xs:text-[10px] sm:text-xs text-slate-500 dark:text-slate-400">{{ studentsList.length }} shown</p>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px]">
                            <thead>
                                <tr class="bg-gradient-to-r from-slate-50 to-blue-50/50 dark:from-slate-800/50 dark:to-slate-800/30 border-b border-slate-200 dark:border-slate-700">
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">ID</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Photo</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Student Name</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Class & Section</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Year</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Father</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Phone</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-left text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Status</th>
                                    <th class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-right text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="student in studentsList"
                                    :key="student.id"
                                    class="group border-b border-slate-100 dark:border-slate-800 hover:bg-gradient-to-r hover:from-blue-50/40 hover:to-indigo-50/40 dark:hover:from-blue-950/30 dark:hover:to-indigo-950/30 transition-all duration-200"
                                >
                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 tabular-nums">
                                            #{{ student.id }}
                                        </span>
                                    </td>

                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <div class="relative">
                                            <div class="w-8 h-8 sm:w-9 sm:h-9 lg:w-10 lg:h-10 rounded-full bg-gradient-to-br from-blue-400 to-indigo-600 flex items-center justify-center overflow-hidden shadow-md ring-2 ring-white dark:ring-slate-800 group-hover:ring-blue-200 dark:group-hover:ring-blue-700 transition-all">
                                                <img v-if="student.student_photo" :src="'/storage/' + student.student_photo" :alt="student.student_name" class="w-full h-full object-cover" />
                                                <span v-else class="text-white font-bold text-[10px] sm:text-xs lg:text-sm">{{ student.student_name?.charAt(0)?.toUpperCase() || '?' }}</span>
                                            </div>
                                            <span
                                                :class="['absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full border-2 border-white dark:border-slate-900 shadow-sm', getStatusDotClass(student.status)]"
                                            ></span>
                                        </div>
                                    </td>

                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <div class="min-w-0">
                                            <div class="text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100 truncate max-w-[180px] group-hover:text-blue-700 dark:group-hover:text-blue-400 transition-colors">
                                                {{ student.student_name }}
                                            </div>
                                            <div class="text-[10px] sm:text-xs text-slate-400 dark:text-slate-500 truncate font-mono">{{ student.student_id }}</div>
                                        </div>
                                    </td>

                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <div>
                                            <div class="text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200">{{ student.class }}</div>
                                            <div class="text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">{{ student.section || 'No Section' }}</div>
                                        </div>
                                    </td>

                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <span class="inline-flex items-center px-1.5 sm:px-2 py-0.5 rounded-md bg-indigo-50 dark:bg-indigo-900/40 text-[10px] xs:text-xs font-bold text-indigo-700 dark:text-indigo-300 tabular-nums">
                                            {{ student.academic_year }}
                                        </span>
                                    </td>
                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <span class="text-xs sm:text-sm text-slate-700 dark:text-slate-200 truncate max-w-[140px] block">{{ student.father_name || 'N/A' }}</span>
                                    </td>
                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <span class="text-xs sm:text-sm text-slate-700 dark:text-slate-200 font-mono tabular-nums whitespace-nowrap">{{ student.father_phone || 'N/A' }}</span>
                                    </td>

                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4">
                                        <span :class="['inline-flex items-center gap-1 sm:gap-1.5 px-2 sm:px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold whitespace-nowrap ring-1', getStatusBadgeClass(student.status)]">
                                            <span :class="['w-1.5 h-1.5 rounded-full', getStatusDotClass(student.status)]"></span>
                                            {{ student.status?.charAt(0)?.toUpperCase() + student.status?.slice(1) }}
                                        </span>
                                    </td>

                                    <td class="px-3 sm:px-4 lg:px-6 py-2.5 sm:py-3 lg:py-4 text-right">
                                        <div class="inline-flex items-center gap-0.5 sm:gap-1 rounded-lg bg-slate-50/80 dark:bg-slate-800/80 p-0.5 border border-slate-200 dark:border-slate-700">
                                            <button
                                                @click="openEditModal(student)"
                                                class="p-1.5 sm:p-2 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 hover:text-indigo-700 dark:hover:text-indigo-300 active:scale-90 transition-all"
                                                title="Edit Student"
                                            >
                                                <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 lg:w-[18px] lg:h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>

                                            <div class="w-px h-4 bg-slate-200 dark:bg-slate-700"></div>

                                            <button
                                                @click="showIdCard(student)"
                                                class="p-1.5 sm:p-2 rounded-md text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/40 hover:text-blue-700 dark:hover:text-blue-300 active:scale-90 transition-all"
                                                title="View ID Card"
                                            >
                                                <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 lg:w-[18px] lg:h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                                                </svg>
                                            </button>

                                            <div class="w-px h-4 bg-slate-200 dark:bg-slate-700"></div>

                                            <button
                                                @click="confirmDelete(student)"
                                                class="p-1.5 sm:p-2 rounded-md text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-900/40 hover:text-rose-700 dark:hover:text-rose-300 active:scale-90 transition-all"
                                                title="Delete"
                                            >
                                                <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 lg:w-[18px] lg:h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <tr v-if="studentsList.length === 0">
                                    <td colspan="9" class="px-4 sm:px-6 py-12 sm:py-16 text-center">
                                        <div class="flex flex-col items-center justify-center max-w-md mx-auto">
                                            <div class="relative mb-4 sm:mb-6">
                                                <div class="absolute inset-0 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-full blur-2xl opacity-30"></div>
                                                <div class="relative p-4 sm:p-5 rounded-full bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-800 dark:to-slate-700 border-2 border-white dark:border-slate-700 shadow-inner">
                                                    <svg class="w-10 h-10 sm:w-12 sm:h-12 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                                    </svg>
                                                </div>
                                            </div>
                                            <h3 class="text-base sm:text-lg font-bold text-slate-700 dark:text-slate-200 mb-1">No Students Found</h3>
                                            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mb-4 sm:mb-5">
                                                No students for year <strong class="text-slate-700 dark:text-slate-200">{{ selectedYear }}</strong>.
                                            </p>
                                            <Link
                                                :href="route('students.create')"
                                                class="group inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-5 sm:px-6 py-2.5 sm:py-3 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-blue-500/25 transition-all duration-300 hover:shadow-xl hover:shadow-blue-500/40 hover:-translate-y-0.5 active:scale-95"
                                            >
                                                <svg class="w-4 h-4 sm:w-5 sm:h-5 transition-transform group-hover:rotate-90 duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                                                </svg>
                                                Add New Student
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINATION -->
                    <div
                        v-if="students?.links && students.links.length > 3"
                        class="px-3 sm:px-6 py-3 sm:py-4 border-t border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-50/50 to-blue-50/50 dark:from-slate-800/30 dark:to-slate-800/20 flex flex-col sm:flex-row items-center justify-between gap-2.5 sm:gap-3"
                    >
                        <div class="text-[10px] xs:text-xs sm:text-sm text-slate-600 dark:text-slate-300 text-center sm:text-left">
                            Showing <span class="font-bold text-slate-800 dark:text-slate-100 tabular-nums">{{ students.from ?? 0 }}</span> to
                            <span class="font-bold text-slate-800 dark:text-slate-100 tabular-nums">{{ students.to ?? 0 }}</span> of
                            <span class="font-bold text-blue-600 dark:text-blue-400 tabular-nums">{{ students.total ?? 0 }}</span> students
                        </div>

                        <div class="flex items-center gap-0.5 sm:gap-1 flex-wrap justify-center">
                            <template v-for="(link, index) in students.links" :key="index">
                                <template v-if="link.url === null">
                                    <span
                                        class="px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-[10px] xs:text-xs sm:text-sm font-medium text-slate-300 dark:text-slate-600 bg-slate-100 dark:bg-slate-800 cursor-not-allowed"
                                        v-html="link.label"
                                    ></span>
                                </template>
                                <template v-else>
                                    <Link
                                        :href="link.url"
                                        preserve-scroll
                                        preserve-state
                                        :class="[
                                            'px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-[10px] xs:text-xs sm:text-sm font-semibold transition-all duration-200',
                                            link.active
                                                ? 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-500/30 ring-1 ring-blue-300 dark:ring-blue-700'
                                                : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:border-blue-300 dark:hover:border-blue-700 hover:text-blue-700 dark:hover:text-blue-300 hover:shadow-sm'
                                        ]"
                                        v-html="link.label"
                                    />
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== EDIT MODAL ==================== -->
        <div
            v-if="showEditModal"
            class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-2 sm:p-4 bg-black/60 dark:bg-black/80 backdrop-blur-sm overflow-y-auto"
            @click.self="closeEditModal"
        >
            <div class="bg-white dark:bg-slate-900 rounded-xl sm:rounded-2xl max-w-4xl w-full my-3 sm:my-8 shadow-2xl overflow-hidden transition-colors duration-300">
                <div class="relative bg-gradient-to-r from-indigo-600 via-indigo-700 to-purple-700 px-3 xs:px-4 sm:px-6 py-3 xs:py-4 sm:py-5">
                    <div class="absolute inset-0 opacity-10 pointer-events-none">
                        <div class="absolute top-0 right-0 w-48 sm:w-64 h-48 sm:h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                    </div>
                    <div class="relative flex items-center justify-between gap-2 sm:gap-3">
                        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                            <div class="p-1.5 sm:p-2 bg-white/20 rounded-lg sm:rounded-xl backdrop-blur-sm flex-shrink-0">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 lg:w-6 lg:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm xs:text-base sm:text-lg font-bold text-white truncate">Edit Student</h3>
                                <p class="text-[10px] xs:text-xs sm:text-sm text-indigo-100/90 truncate">{{ editingStudent?.student_name }} ({{ editingStudent?.student_id }})</p>
                            </div>
                        </div>
                        <button @click="closeEditModal" class="p-1.5 sm:p-2 rounded-lg text-white/80 hover:bg-white/20 active:scale-90 transition-all flex-shrink-0">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <form @submit.prevent="submitEditForm" class="p-3 xs:p-4 sm:p-6 max-h-[75vh] sm:max-h-[80vh] overflow-y-auto">
                    <!-- Student Information -->
                    <div class="mb-4 sm:mb-6">
                        <h4 class="text-[10px] xs:text-xs sm:text-sm font-bold text-indigo-700 dark:text-indigo-300 uppercase tracking-wider mb-2.5 sm:mb-4 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-600 dark:bg-indigo-400"></span>
                            Student Information
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5 xs:gap-3 sm:gap-4">
                            <div class="sm:col-span-2 lg:col-span-1">
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Student Name <span class="text-red-500">*</span></label>
                                <input v-model="editForm.studentName" type="text" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/10" />
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Student ID</label>
                                <input :value="editingStudent?.student_id" type="text" readonly class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-semibold cursor-not-allowed outline-none" />
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Date of Birth</label>
                                <input v-model="editForm.dateOfBirth" type="date" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/10" />
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Gender</label>
                                <select v-model="editForm.gender" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800">
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Blood Group</label>
                                <select v-model="editForm.bloodGroup" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-800">
                                    <option value="">Select Blood Group</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2 lg:col-span-1">
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Photo</label>
                                <input type="file" accept="image/*" @change="handleEditPhotoChange" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 text-[10px] xs:text-xs sm:text-sm text-slate-700 dark:text-slate-200 file:mr-2 sm:file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-2.5 sm:file:px-3 file:py-1 sm:file:py-1.5 file:text-[10px] sm:file:text-xs file:text-white file:font-semibold file:cursor-pointer hover:file:bg-indigo-700 focus:border-indigo-500" />
                            </div>
                        </div>
                    </div>

                    <!-- Academic Information -->
                    <div class="mb-4 sm:mb-6">
                        <h4 class="text-[10px] xs:text-xs sm:text-sm font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider mb-2.5 sm:mb-4 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 dark:bg-emerald-400"></span>
                            Academic Information
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5 xs:gap-3 sm:gap-4">
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Academic Year *</label>
                                <input v-model="editForm.academicYear" type="number" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800" />
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Class *</label>
                                <select v-model="editForm.class" @change="handleEditClassChange" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800">
                                    <option value="">Select Class</option>
                                    <option v-for="cls in availableClasses" :key="cls" :value="cls">{{ cls }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Section</label>
                                <select v-model="editForm.section" :disabled="!editForm.class || editAvailableSections.length === 0" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 disabled:opacity-50">
                                    <option value="">Select Section</option>
                                    <option v-for="section in editAvailableSections" :key="section" :value="section">{{ section }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Roll Number</label>
                                <input v-model="editForm.rollNumber" type="text" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800" />
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Admission Date *</label>
                                <input v-model="editForm.admissionDate" type="date" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800" />
                            </div>
                            <div>
                                <label class="mb-1 sm:mb-1.5 block text-[10px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Status</label>
                                <select v-model="editForm.status" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800">
                                    <option value="active">🟢 Active</option>
                                    <option value="inactive">🔴 Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Parent Info -->
                    <div class="mb-4 sm:mb-6">
                        <h4 class="text-[10px] xs:text-xs sm:text-sm font-bold text-purple-700 dark:text-purple-400 uppercase tracking-wider mb-2.5 sm:mb-4 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-600 dark:bg-purple-400"></span>
                            Parent / Guardian Information
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 xs:gap-3 sm:gap-4">
                            <input v-model="editForm.fatherName" placeholder="Father's Name" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800" />
                            <input v-model="editForm.fatherPhone" placeholder="Father's Phone" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800" />
                            <input v-model="editForm.motherName" placeholder="Mother's Name" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800" />
                            <input v-model="editForm.motherPhone" placeholder="Mother's Phone" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800" />
                            <input v-model="editForm.guardianName" placeholder="Guardian Name" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800" />
                            <input v-model="editForm.guardianPhone" placeholder="Guardian Phone" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800" />
                            <input v-model="editForm.guardianRelation" placeholder="Guardian Relation" class="w-full sm:col-span-2 rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800" />
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="mb-4 sm:mb-6">
                        <h4 class="text-[10px] xs:text-xs sm:text-sm font-bold text-orange-700 dark:text-orange-400 uppercase tracking-wider mb-2.5 sm:mb-4 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-orange-600 dark:bg-orange-400"></span>
                            Address
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 xs:gap-3 sm:gap-4">
                            <textarea v-model="editForm.presentAddress" rows="3" placeholder="Present Address" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-orange-500 focus:bg-white dark:focus:bg-slate-800 resize-none"></textarea>
                            <div>
                                <label class="flex items-center gap-2 mb-2 cursor-pointer select-none">
                                    <input v-model="editSameAsPresent" @change="editCopyPresentToPermanent" type="checkbox" class="w-3.5 h-3.5 sm:w-4 sm:h-4 rounded border-2 border-slate-300 dark:border-slate-600 cursor-pointer accent-orange-500" />
                                    <span class="text-[10px] xs:text-xs text-slate-600 dark:text-slate-300 font-medium">Same as Present</span>
                                </label>
                                <textarea v-model="editForm.permanentAddress" rows="3" placeholder="Permanent Address" class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-3 lg:px-4 py-1.5 sm:py-2 lg:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-orange-500 focus:bg-white dark:focus:bg-slate-800 resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col-reverse xs:flex-row items-stretch xs:items-center justify-end gap-2 sm:gap-3 pt-3 sm:pt-4 border-t border-slate-200 dark:border-slate-700">
                        <button
                            type="button"
                            @click="closeEditModal"
                            class="w-full xs:w-auto px-4 sm:px-6 py-2 sm:py-2.5 rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 active:scale-95 transition-transform"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="group w-full xs:w-auto inline-flex items-center justify-center gap-2 px-4 sm:px-6 py-2 sm:py-2.5 rounded-lg sm:rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 text-xs sm:text-sm font-semibold text-white hover:from-indigo-700 hover:to-purple-700 hover:shadow-lg hover:shadow-indigo-500/25 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                        >
                            <svg v-if="editForm.processing" class="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ editForm.processing ? 'Updating...' : 'Update Student' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ==================== DELETE MODAL ==================== -->
        <div
            v-if="showDeleteModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-black/60 dark:bg-black/80 backdrop-blur-sm"
            @click.self="showDeleteModal = false"
        >
            <div class="bg-white dark:bg-slate-900 rounded-xl sm:rounded-2xl max-w-md w-full shadow-2xl p-5 sm:p-6 transform transition-all">
                <div class="flex items-center justify-center mb-3 sm:mb-4">
                    <div class="relative">
                        <div class="absolute inset-0 bg-rose-500 rounded-full blur-xl opacity-40 animate-pulse"></div>
                        <div class="relative w-14 h-14 sm:w-16 sm:h-16 rounded-full bg-gradient-to-br from-rose-100 to-red-100 dark:from-rose-900/60 dark:to-red-900/60 ring-4 ring-rose-50 dark:ring-rose-950/60 flex items-center justify-center">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                    </div>
                </div>
                <h3 class="text-base sm:text-lg lg:text-xl font-bold text-center text-slate-800 dark:text-slate-100 mb-2">Delete Student</h3>
                <p class="text-xs sm:text-sm text-center text-slate-500 dark:text-slate-400 mb-4 sm:mb-6">
                    Delete <span class="font-semibold text-slate-700 dark:text-slate-200">{{ studentToDelete?.student_name }}</span>?<br>
                    <span class="text-[10px] sm:text-xs lg:text-sm text-rose-500 dark:text-rose-400 font-semibold">This action cannot be undone.</span>
                </p>
                <div class="flex gap-2 sm:gap-3">
                    <button
                        @click="showDeleteModal = false"
                        class="flex-1 rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 active:scale-95 transition-transform"
                    >
                        Cancel
                    </button>
                    <button
                        @click="deleteStudent"
                        class="flex-1 rounded-lg sm:rounded-xl bg-gradient-to-r from-rose-600 to-red-600 px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-semibold text-white hover:from-rose-700 hover:to-red-700 hover:shadow-lg hover:shadow-rose-500/25 active:scale-95 transition-all"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>

        <!-- ==================== ID CARD MODAL (redesigned + print-ready) ==================== -->
        <Teleport to="body">
            <div
                v-if="showIdCardModal"
                class="id-print-root fixed inset-0 z-[100] flex items-start sm:items-center justify-center p-2 sm:p-4 bg-slate-950/85 backdrop-blur-md overflow-y-auto"
                @click.self="closeIdCard"
            >
                <div class="id-print-wrap w-full max-w-5xl my-3 sm:my-8">

                    <!-- Modal header (hidden while printing) -->
                    <div class="no-print flex items-center justify-between mb-3 sm:mb-6 gap-2">
                        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                            <div class="p-1.5 sm:p-2.5 bg-white/10 rounded-lg sm:rounded-xl backdrop-blur-sm flex-shrink-0 ring-1 ring-white/20">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 lg:w-6 lg:h-6 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm xs:text-base sm:text-lg lg:text-xl font-bold text-white truncate">Student ID Card</h3>
                                <p class="text-[10px] xs:text-xs sm:text-sm text-slate-400">Preview & Print</p>
                            </div>
                        </div>
                        <button @click="closeIdCard" class="p-1.5 sm:p-2 rounded-lg sm:rounded-xl text-white/70 hover:bg-white/10 hover:text-white active:scale-90 transition-all flex-shrink-0">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 lg:w-6 lg:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Cards (this block is what gets printed) -->
                    <div class="id-print-sheet flex flex-col lg:flex-row items-center lg:items-start justify-center gap-5 sm:gap-8 lg:gap-10">

                        <!-- ================= FRONT ================= -->
                        <div class="id-print-side flex flex-col items-center">
                            <div class="no-print mb-2.5 sm:mb-4">
                                <span class="px-3 sm:px-4 py-1 sm:py-1.5 bg-emerald-500/15 text-emerald-300 text-[9px] sm:text-[11px] font-bold rounded-full uppercase tracking-[0.2em] border border-emerald-400/30">Front</span>
                            </div>

                            <div class="id-print-slot">
                                <div id="id-card-front" class="id-card relative bg-white rounded-xl shadow-2xl overflow-hidden" style="width: 300px; height: 478px;">

                                    <!-- Header -->
                                    <div class="relative h-[132px] bg-gradient-to-br from-emerald-700 via-green-800 to-emerald-950 px-4 pt-5">
                                        <div class="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-amber-300 via-yellow-400 to-amber-300"></div>
                                        <div class="absolute -top-10 -right-10 w-32 h-32 rounded-full bg-white/5"></div>
                                        <div class="absolute top-14 -left-10 w-28 h-28 rounded-full bg-white/5"></div>

                                        <div class="relative flex items-center gap-3">
                                            <!-- School logo (dynamic) with book-icon fallback -->
                                            <div class="w-11 h-11 rounded-lg bg-white/10 flex items-center justify-center border border-white/20 flex-shrink-0 overflow-hidden">
                                                <img
                                                    v-if="schoolLogo && !logoError"
                                                    :src="schoolLogo"
                                                    :alt="schoolName"
                                                    class="w-full h-full object-contain p-1"
                                                    @error="logoError = true"
                                                />
                                                <svg
                                                    v-else
                                                    class="w-6 h-6 text-amber-200"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <h4 class="text-white font-bold text-[13px] leading-tight tracking-[0.1em] uppercase line-clamp-2 break-words">
                                                    {{ schoolName }}
                                                </h4>
                                                <p class="mt-1 text-amber-200/80 text-[8px] tracking-[0.2em] uppercase truncate">
                                                    {{ schoolMotto }}
                                                </p>
                                            </div>
                                        </div>

                                        <!-- Curved bottom edge -->
                                        <svg class="absolute -bottom-px left-0 w-full h-5 text-white" viewBox="0 0 300 20" preserveAspectRatio="none">
                                            <path d="M0 20 L0 10 C60 0 110 16 170 10 C220 5 260 0 300 8 L300 20 Z" fill="currentColor" />
                                        </svg>
                                    </div>

                                    <!-- Photo -->
                                    <div class="relative z-10 flex justify-center -mt-[54px]">
                                        <div class="w-[112px] h-[112px] rounded-full bg-gradient-to-br from-amber-200 via-yellow-300 to-amber-400 p-[3px] shadow-xl">
                                            <div class="w-full h-full rounded-full bg-white p-[3px]">
                                                <div class="w-full h-full rounded-full bg-emerald-50 flex items-center justify-center overflow-hidden">
                                                    <img v-if="selectedStudent?.student_photo" :src="'/storage/' + selectedStudent.student_photo" class="w-full h-full object-cover" />
                                                    <span v-else class="text-emerald-700 font-bold text-4xl">{{ selectedStudent?.student_name?.charAt(0)?.toUpperCase() || '?' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Name + class -->
                                    <div class="text-center mt-2.5 px-5">
                                        <h3 class="text-[16px] font-bold text-emerald-950 uppercase leading-tight line-clamp-2 break-words">{{ selectedStudent?.student_name }}</h3>
                                        <div class="w-10 h-[2px] bg-amber-400 mx-auto my-1.5"></div>
                                        <span class="inline-block px-3 py-0.5 rounded-full bg-emerald-50 border border-emerald-100 text-[10px] font-semibold text-emerald-700 uppercase tracking-wide">
                                            {{ selectedStudent?.class }} &middot; {{ selectedStudent?.section || 'N/A' }}
                                        </span>
                                    </div>

                                    <!-- Details -->
                                    <div class="px-5 mt-3 grid grid-cols-2 gap-1.5">
                                        <div class="col-span-2 p-2 rounded-lg bg-emerald-50 border border-emerald-100">
                                            <p class="text-[7px] font-bold text-emerald-600 uppercase tracking-wider">Student ID</p>
                                            <p class="text-[12px] font-bold text-emerald-950 truncate">{{ selectedStudent?.student_id }}</p>
                                        </div>
                                        <div class="p-2 rounded-lg bg-slate-50 border border-slate-100">
                                            <p class="text-[7px] font-bold text-slate-400 uppercase tracking-wider">DOB</p>
                                            <p class="text-[11px] font-bold text-slate-800 truncate">{{ selectedStudent?.date_of_birth || '—' }}</p>
                                        </div>
                                        <div class="p-2 rounded-lg bg-slate-50 border border-slate-100">
                                            <p class="text-[7px] font-bold text-slate-400 uppercase tracking-wider">Join</p>
                                            <p class="text-[11px] font-bold text-slate-800 truncate">{{ selectedStudent?.admission_date || '—' }}</p>
                                        </div>
                                        <div class="p-2 rounded-lg bg-slate-50 border border-slate-100">
                                            <p class="text-[7px] font-bold text-slate-400 uppercase tracking-wider">Blood Group</p>
                                            <p class="text-[11px] font-bold text-rose-700 truncate">{{ selectedStudent?.blood_group || '—' }}</p>
                                        </div>
                                        <div class="p-2 rounded-lg bg-slate-50 border border-slate-100">
                                            <p class="text-[7px] font-bold text-slate-400 uppercase tracking-wider">Phone</p>
                                            <p class="text-[11px] font-bold text-slate-800 truncate">{{ idCardPhone }}</p>
                                        </div>
                                    </div>

                                    <!-- Footer -->
                                    <div class="absolute bottom-0 left-0 right-0">
                                        <div class="h-[2px] bg-gradient-to-r from-amber-300 via-yellow-400 to-amber-300"></div>
                                        <div class="bg-emerald-950 px-5 py-2.5">
                                            <p class="text-center text-[9px] text-amber-200/90 font-mono tracking-[0.3em] truncate">{{ selectedStudent?.student_id }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ================= BACK ================= -->
                        <div class="id-print-side flex flex-col items-center">
                            <div class="no-print mb-2.5 sm:mb-4">
                                <span class="px-3 sm:px-4 py-1 sm:py-1.5 bg-emerald-500/15 text-emerald-300 text-[9px] sm:text-[11px] font-bold rounded-full uppercase tracking-[0.2em] border border-emerald-400/30">Back</span>
                            </div>

                            <div class="id-print-slot">
                                <div id="id-card-back" class="id-card relative bg-white rounded-xl shadow-2xl overflow-hidden" style="width: 300px; height: 478px;">

                                    <!-- Header -->
                                    <div class="relative h-[64px] bg-gradient-to-br from-emerald-700 via-green-800 to-emerald-950 px-4 flex items-center">
                                        <div class="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-amber-300 via-yellow-400 to-amber-300"></div>
                                        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-white/5"></div>
                                        <div class="relative flex items-center gap-3 w-full">
                                            <div class="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center border border-white/20 flex-shrink-0 overflow-hidden">
                                                <img
                                                    v-if="schoolLogo && !logoError"
                                                    :src="schoolLogo"
                                                    :alt="schoolName"
                                                    class="w-full h-full object-contain p-0.5"
                                                    @error="logoError = true"
                                                />
                                                <svg
                                                    v-else
                                                    class="w-5 h-5 text-amber-200"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                                </svg>
                                            </div>
                                            <h4 class="flex-1 min-w-0 text-white font-bold text-[12px] leading-tight tracking-[0.1em] uppercase line-clamp-2 break-words">{{ schoolName }}</h4>
                                        </div>
                                    </div>

                                    <!-- Body -->
                                    <div class="px-6 pt-4">
                                        <p class="text-center text-[9px] font-bold text-emerald-700 uppercase tracking-[0.25em]">
                                            Student ID Card<template v-if="selectedStudent?.academic_year"> &middot; {{ selectedStudent.academic_year }}</template>
                                        </p>

                                        <!-- QR -->
                                        <div class="flex justify-center mt-2.5">
                                            <div class="p-2.5 bg-white rounded-xl border-2 border-emerald-100 shadow-sm">
                                                <QrcodeVue v-if="qrValue" :value="qrValue" :size="104" level="H" render-as="svg" foreground="#064e3b" background="#ffffff" />
                                            </div>
                                        </div>
                                        <p class="text-center text-[9px] text-slate-500 font-mono tracking-[0.2em] mt-1.5 truncate">{{ selectedStudent?.student_id }}</p>

                                        <!-- Notice -->
                                        <div class="mt-3 rounded-lg bg-emerald-50/70 border border-emerald-100 px-3 py-2 text-center">
                                            <p class="text-[8.5px] leading-snug text-slate-600">
                                                This card is the property of <span class="font-semibold text-emerald-800">{{ schoolName }}</span>.
                                                If found, please return it to the school office.
                                            </p>
                                        </div>

                                        <!-- Signature -->
                                        <div class="text-center mt-4">
                                            <p class="text-lg text-emerald-800 italic leading-none" style="font-family: 'Brush Script MT', cursive;">Gobi Ananth</p>
                                            <div class="w-32 h-px bg-amber-400 mx-auto mt-1"></div>
                                            <p class="text-[7px] text-emerald-700 font-bold mt-1 uppercase tracking-[0.2em]">Authorized Signature</p>
                                        </div>

                                        <!-- School Address (if available) -->
                                        <div v-if="schoolAddress" class="text-center mt-3 pt-2.5 border-t border-emerald-100">
                                            <p class="text-[7px] font-bold text-emerald-700 uppercase tracking-[0.2em] mb-1">Address</p>
                                            <p class="text-[9px] text-slate-600 leading-tight px-2 line-clamp-2">
                                                {{ schoolAddress }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Bottom stripe -->
                                    <div class="absolute bottom-0 left-0 right-0">
                                        <div class="h-[2px] bg-gradient-to-r from-amber-300 via-yellow-400 to-amber-300"></div>
                                        <div class="h-[8px] bg-gradient-to-r from-emerald-800 via-green-800 to-emerald-950"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions (hidden while printing) -->
                    <div class="no-print flex flex-col items-center gap-2.5 mt-5 sm:mt-8">
                        <div class="flex flex-col xs:flex-row items-center justify-center gap-2 sm:gap-3 w-full">
                            <button
                                @click="closeIdCard"
                                class="w-full xs:w-auto px-4 sm:px-6 py-2 sm:py-2.5 rounded-lg sm:rounded-xl border border-white/15 bg-white/5 text-xs sm:text-sm font-medium text-white/90 hover:bg-white/10 active:scale-95 transition-transform"
                            >
                                Close
                            </button>
                            <button
                                @click="printIdCard"
                                class="group w-full xs:w-auto inline-flex items-center justify-center gap-1.5 sm:gap-2 px-4 sm:px-6 py-2 sm:py-2.5 rounded-lg sm:rounded-xl bg-gradient-to-r from-emerald-600 to-green-700 text-xs sm:text-sm font-semibold text-white hover:from-emerald-700 hover:to-green-800 ring-1 ring-amber-300/40 hover:shadow-lg hover:shadow-emerald-500/30 active:scale-95 transition-all"
                            >
                                <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                </svg>
                                Print ID Card
                            </button>
                        </div>
                        <p class="text-[10px] sm:text-xs text-slate-400 text-center">
                            Prints front &amp; back on one A4 sheet at real ID size (54 × 86 mm). Cut along the dashed lines.
                        </p>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
</style>

<!-- ✅ Global (non-scoped) print styles — active ONLY while printIdCard() sets body.printing-id-card -->
<style>
@media print {
    body.printing-id-card,
    html:has(body.printing-id-card) {
        background: #fff !important;
        height: auto !important;
        overflow: visible !important;
    }

    /* Hide the whole app (sidebar, navbar, page) — only the ID card layer stays */
    body.printing-id-card > *:not(.id-print-root) {
        display: none !important;
    }

    body.printing-id-card .id-print-root {
        position: static !important;
        display: block !important;
        width: 100% !important;
        height: auto !important;
        margin: 0 !important;
        padding: 14mm 10mm 0 10mm !important;
        background: #fff !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
        overflow: visible !important;
    }

    body.printing-id-card .id-print-wrap {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
    }

    /* Hide modal title, "Front/Back" pills, buttons and hint text */
    body.printing-id-card .no-print {
        display: none !important;
    }

    /* Front + back side by side */
    body.printing-id-card .id-print-sheet {
        display: flex !important;
        flex-direction: row !important;
        align-items: flex-start !important;
        justify-content: center !important;
        gap: 14mm !important;
    }

    body.printing-id-card .id-print-side {
        display: block !important;
        margin: 0 !important;
    }

    /* Real CR80 size slot: 54 × 86 mm, with cut guide */
    body.printing-id-card .id-print-slot {
        width: 54mm;
        height: 86mm;
        overflow: hidden;
        border-radius: 3mm;
        outline: 0.25mm dashed #94a3b8;
        outline-offset: 0.6mm;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    /* 300px design width × 0.68 = 54 mm  |  478px × 0.68 = 86 mm */
    body.printing-id-card .id-card {
        transform: scale(0.68);
        transform-origin: top left;
        box-shadow: none !important;
        border-radius: 0 !important;
    }

    /* Make sure gradients, colors and backgrounds are printed */
    body.printing-id-card .id-card,
    body.printing-id-card .id-card * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>