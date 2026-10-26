<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import { computed, watch, onMounted, ref } from 'vue'

const props = defineProps({
    classes: {
        type: Array,
        default: () => [],
    },

    currentAcademicYear: {
        type: Number,
        default: () => new Date().getFullYear(),
    },

    generatedStudentId: {
        type: String,
        default: '',
    },

    flash: {
        type: Object,
        default: () => ({}),
    },
})

// ============================================================
// Toast State
// ============================================================
const toast = ref({
    show: false,
    message: '',
    type: 'success',
})

const showToast = (message, type = 'success') => {
    toast.value = {
        show: true,
        message,
        type,
    }

    setTimeout(() => {
        toast.value.show = false
    }, 5000)
}

// ============================================================
// Same as Present Address
// ============================================================
const sameAsPresent = ref(true)

// ============================================================
// Student ID Loading State
// ============================================================
const isGeneratingId = ref(false)

// ============================================================
// Academic Years
// ============================================================
const academicYears = computed(() => {
    const currentYear = props.currentAcademicYear

    return [
        currentYear - 1,
        currentYear,
        currentYear + 1,
        currentYear + 2,
        currentYear + 3,
    ]
})

// ============================================================
// Unique Classes from Database
// ============================================================
const availableClasses = computed(() => {
    return [
        ...new Set(
            props.classes
                .map(item => item.class_name)
                .filter(Boolean)
        ),
    ]
})

// ============================================================
// Guardian Relations
// ============================================================
const guardianRelations = [
    'Father',
    'Mother',
    'Brother',
    'Sister',
    'Uncle',
    'Aunt',
    'Grandfather',
    'Grandmother',
    'Cousin',
    'Guardian',
    'Other',
]

// ============================================================
// Form with Inertia
// ============================================================
const form = useForm({
    studentName: '',
    studentId: props.generatedStudentId || '',
    dateOfBirth: '',
    gender: '',
    bloodGroup: '',
    studentPhoto: null,
    academicYear:
        props.currentAcademicYear ||
        new Date().getFullYear(),
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
// Sections based on selected class
// ============================================================
const availableSections = computed(() => {
    if (!form.class) {
        return []
    }

    return [
        ...new Set(
            props.classes
                .filter(item => item.class_name === form.class)
                .map(item => item.section)
                .filter(Boolean)
        ),
    ]
})

// ============================================================
// Generate Next Student ID
// ============================================================
const generateNextStudentId = async () => {
    if (isGeneratingId.value) {
        return
    }

    isGeneratingId.value = true

    try {
        const response = await fetch(
            route('students.generate-id') +
            `?year=${form.academicYear}`,
            {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }
        )

        if (!response.ok) {
            throw new Error(
                `Failed to generate Student ID: ${response.status}`
            )
        }

        const data = await response.json()

        if (!data.generatedStudentId) {
            throw new Error('Student ID was not returned by server.')
        }

        form.studentId = data.generatedStudentId

        if (form.errors.studentId) {
            form.clearErrors('studentId')
        }
    } catch (error) {
        console.error(
            'Student ID generation error:',
            error
        )

        showToast(
            'Unable to generate Student ID.',
            'error'
        )
    } finally {
        isGeneratingId.value = false
    }
}

// ============================================================
// Watch for Class Changes
// ============================================================
watch(
    () => form.class,
    () => {
        form.section = ''
    }
)

// ============================================================
// Watch Present Address
// ============================================================
watch(
    () => form.presentAddress,
    (newValue) => {
        if (sameAsPresent.value) {
            form.permanentAddress = newValue
        }
    }
)

// ============================================================
// Watch Same as Present
// ============================================================
watch(
    () => sameAsPresent.value,
    (newValue) => {
        if (newValue) {
            form.permanentAddress = form.presentAddress
        }
    }
)

// ============================================================
// Watch Academic Year
// ============================================================
watch(
    () => form.academicYear,
    async (newYear, oldYear) => {
        if (newYear === oldYear) {
            return
        }

        form.class = ''
        form.section = ''

        await generateNextStudentId()
    }
)

// ============================================================
// Copy Present Address to Permanent
// ============================================================
const copyPresentToPermanent = () => {
    if (sameAsPresent.value) {
        form.permanentAddress = form.presentAddress
    } else {
        form.permanentAddress = ''
    }
}

// ============================================================
// Handle Photo Upload
// ============================================================
const handlePhotoChange = (event) => {
    form.studentPhoto =
        event.target.files?.[0] ?? null
}

// ============================================================
// Reset Form
// ============================================================
const resetForm = async () => {
    form.reset()

    sameAsPresent.value = true

    await generateNextStudentId()
}

// ============================================================
// Flash Messages
// ============================================================
onMounted(() => {
    if (props.flash?.success) {
        showToast(
            props.flash.success,
            'success'
        )
    }

    if (props.flash?.error) {
        showToast(
            props.flash.error,
            'error'
        )
    }

    if (!form.studentId) {
        generateNextStudentId()
    }
})

// ============================================================
// Submit Form
// ============================================================
const submitForm = () => {
    form.post(route('students.store'), {
        forceFormData: true,
        preserveScroll: true,

        onSuccess: (page) => {
            if (page.props.flash?.success) {
                showToast(
                    page.props.flash.success,
                    'success'
                )
            }

            form.reset()

            sameAsPresent.value = true

            const nextStudentId =
                page.props.generatedStudentId ||
                page.props.flash?.generatedStudentId ||
                ''

            if (nextStudentId) {
                form.studentId = nextStudentId
            } else {
                generateNextStudentId()
            }
        },

        onError: (errors) => {
            if (errors.studentId) {
                showToast(
                    errors.studentId,
                    'error'
                )
            } else if (errors.class) {
                showToast(
                    errors.class,
                    'error'
                )
            } else if (errors.section) {
                showToast(
                    errors.section,
                    'error'
                )
            } else {
                showToast(
                    'Please fix the errors and try again.',
                    'error'
                )
            }
        },
    })
}
</script>

<template>
    <Head title="Create Student" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative w-full min-h-screen overflow-x-hidden bg-gradient-to-br from-slate-50 via-blue-50/30 to-indigo-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 transition-colors duration-300">

            <!-- Decorative blobs - overflow-hidden wrapper এ -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-32 -left-32 h-64 w-64 sm:h-80 sm:w-80 rounded-full bg-blue-200/20 dark:bg-blue-800/10 blur-3xl"></div>
                <div class="absolute top-1/2 -right-32 h-64 w-64 sm:h-80 sm:w-80 rounded-full bg-indigo-200/20 dark:bg-indigo-800/10 blur-3xl"></div>
            </div>

            <div class="relative mx-auto max-w-[1600px] px-2.5 xs:px-3 sm:px-4 md:px-6 lg:px-8 py-3 xs:py-4 sm:py-6 lg:py-8">

                <!-- TOAST NOTIFICATION -->
                <div
                    v-if="toast.show"
                    :class="[
                        'fixed top-3 right-3 left-3 xs:left-auto z-50 w-auto xs:max-w-sm xs:w-full p-3 sm:p-4 rounded-xl shadow-2xl transition-all duration-500 ease-in-out',
                        toast.type === 'success' ? 'bg-emerald-50 dark:bg-emerald-900/30 border-2 border-emerald-500 dark:border-emerald-600' : '',
                        toast.type === 'error' ? 'bg-rose-50 dark:bg-rose-900/30 border-2 border-rose-500 dark:border-rose-600' : '',
                        toast.type === 'warning' ? 'bg-amber-50 dark:bg-amber-900/30 border-2 border-amber-500 dark:border-amber-600' : '',
                        toast.type === 'info' ? 'bg-sky-50 dark:bg-sky-900/30 border-2 border-sky-500 dark:border-sky-600' : '',
                    ]"
                    style="animation: slideInRight 0.5s ease-out"
                >
                    <div class="flex items-start gap-2.5 sm:gap-3">
                        <div class="flex-shrink-0 mt-0.5">
                            <svg v-if="toast.type === 'success'" class="w-4 h-4 sm:w-5 sm:h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <svg v-else-if="toast.type === 'error'" class="w-4 h-4 sm:w-5 sm:h-5 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <svg v-else-if="toast.type === 'warning'" class="w-4 h-4 sm:w-5 sm:h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <svg v-else class="w-4 h-4 sm:w-5 sm:h-5 text-sky-600 dark:text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>

                        <div class="flex-1 min-w-0">
                            <p :class="[
                                'text-xs sm:text-sm font-medium break-words',
                                toast.type === 'success' ? 'text-emerald-800 dark:text-emerald-200' : '',
                                toast.type === 'error' ? 'text-rose-800 dark:text-rose-200' : '',
                                toast.type === 'warning' ? 'text-amber-800 dark:text-amber-200' : '',
                                toast.type === 'info' ? 'text-sky-800 dark:text-sky-200' : '',
                            ]">
                                {{ toast.message }}
                            </p>
                        </div>

                        <button
                            @click="toast.show = false"
                            class="flex-shrink-0 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors p-0.5"
                        >
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- PAGE HEADER -->
                <div class="mb-4 sm:mb-5 lg:mb-6">
                    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-3 sm:gap-4">
                        <div class="flex items-center gap-2.5 sm:gap-3 lg:gap-4 w-full lg:w-auto">
                            <div class="p-2 sm:p-2.5 lg:p-3 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl sm:rounded-2xl shadow-lg shadow-blue-500/20 flex-shrink-0">
                                <svg class="w-5 h-5 sm:w-6 sm:h-6 lg:w-7 lg:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-lg xs:text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 dark:from-slate-100 dark:to-slate-300 bg-clip-text text-transparent truncate">
                                    Create Student
                                </h1>
                                <p class="text-[11px] sm:text-xs lg:text-sm text-slate-500 dark:text-slate-400 flex items-center gap-1.5 sm:gap-2 mt-0.5 sm:mt-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
                                    <span class="truncate">Add a new student to the system</span>
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col xs:flex-row items-stretch xs:items-center gap-2 sm:gap-3 w-full lg:w-auto">
                            <Link
                                :href="route('students.index')"
                                class="group inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm px-4 sm:px-5 py-2.5 text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 transition-all duration-300 hover:bg-white dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-lg active:scale-95"
                            >
                                <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                @click="submitForm"
                                :disabled="form.processing"
                                class="inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 sm:px-6 py-2.5 text-xs sm:text-sm font-medium text-white transition-all duration-300 hover:from-blue-700 hover:to-indigo-700 hover:shadow-lg hover:shadow-blue-500/25 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg v-if="!form.processing" class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                <svg v-else class="w-4 h-4 sm:w-5 sm:h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                {{ form.processing ? 'Creating...' : 'Create Student' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- FORM -->
                <form @submit.prevent="submitForm">
                    <div class="space-y-4 sm:space-y-5 lg:space-y-6">

                        <!-- SECTION 1: Student Information -->
                        <div class="w-full overflow-hidden rounded-xl sm:rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-white/50 dark:border-slate-800 shadow-xl shadow-slate-200/50 dark:shadow-black/40 transition-colors duration-300">
                            <div class="relative overflow-hidden bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 px-3 xs:px-4 sm:px-6 lg:px-8 py-3 xs:py-4 sm:py-5">
                                <div class="absolute inset-0 opacity-10 pointer-events-none">
                                    <div class="absolute top-0 right-0 w-48 sm:w-64 h-48 sm:h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    <div class="absolute bottom-0 left-0 w-32 sm:w-48 h-32 sm:h-48 bg-white rounded-full blur-3xl translate-y-1/3 -translate-x-1/3"></div>
                                </div>
                                <div class="relative flex items-center gap-2.5 sm:gap-3">
                                    <div class="p-1.5 sm:p-2 bg-white/20 rounded-lg sm:rounded-xl backdrop-blur-sm flex-shrink-0">
                                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-sm xs:text-base sm:text-lg font-bold text-white truncate">Student Information</h2>
                                        <p class="text-[10px] xs:text-xs sm:text-sm text-blue-100/90 truncate">Personal details of the student</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3.5 xs:gap-4 sm:gap-5 md:gap-6 p-3 xs:p-4 sm:p-6 lg:p-8 sm:grid-cols-2 lg:grid-cols-3">
                                <!-- Student Name -->
                                <div class="sm:col-span-2 lg:col-span-1">
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Student Name
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.studentName"
                                            type="text"
                                            placeholder="Enter student's full name"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                    <p v-if="form.errors.studentName" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.studentName }}
                                    </p>
                                </div>

                                <!-- Student ID -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Student ID / Admission No.
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.studentId"
                                            type="text"
                                            placeholder="STD-2026-00001"
                                            class="w-full rounded-lg sm:rounded-xl border-2 pl-9 sm:pl-10 pr-14 sm:pr-16 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 bg-slate-50/50 dark:bg-slate-800/50 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                            :class="[
                                                form.errors.studentId ? 'border-rose-500 focus:border-rose-500' : 'border-slate-200 dark:border-slate-700'
                                            ]"
                                        />
                                        <div class="absolute inset-y-0 right-0 pr-2.5 sm:pr-3.5 flex items-center gap-1">
                                            <span class="text-[9px] sm:text-[10px] text-blue-600 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/40 px-1.5 py-0.5 rounded font-medium whitespace-nowrap">
                                                Auto
                                            </span>
                                        </div>
                                    </div>
                                    <p class="mt-1 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">
                                        🔄 Auto-generated from database.
                                    </p>
                                    <p v-if="form.errors.studentId" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.studentId }}
                                    </p>
                                </div>

                                <!-- Date of Birth -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Date of Birth
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.dateOfBirth"
                                            type="date"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                </div>

                                <!-- Gender -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Gender
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                        <select
                                            v-model="form.gender"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 appearance-none focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 cursor-pointer"
                                        >
                                            <option value="">Select Gender</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-3 sm:pr-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.gender" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.gender }}
                                    </p>
                                </div>

                                <!-- Blood Group -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Blood Group
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-rose-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                                            </svg>
                                        </div>
                                        <select
                                            v-model="form.bloodGroup"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 appearance-none focus:border-rose-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-rose-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 cursor-pointer"
                                        >
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
                                        <div class="absolute inset-y-0 right-0 pr-3 sm:pr-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Student Photo -->
                                <div class="sm:col-span-2 lg:col-span-1">
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Student Photo
                                    </label>
                                    <div class="relative">
                                        <input
                                            type="file"
                                            accept="image/*"
                                            @change="handlePhotoChange"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 px-2.5 sm:px-4 py-2 sm:py-2.5 lg:py-3 text-[11px] sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 file:mr-2 sm:file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-2.5 sm:file:px-4 file:py-1.5 sm:file:py-2 file:text-[11px] sm:file:text-sm file:font-medium file:text-white hover:file:bg-blue-700 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 cursor-pointer"
                                        />
                                    </div>
                                    <p class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Upload student photo (JPEG, PNG, WebP - Max 2MB)</p>
                                    <p v-if="form.errors.studentPhoto" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.studentPhoto }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 2: Academic Information -->
                        <div class="w-full overflow-hidden rounded-xl sm:rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-white/50 dark:border-slate-800 shadow-xl shadow-slate-200/50 dark:shadow-black/40 transition-colors duration-300">
                            <div class="relative overflow-hidden bg-gradient-to-r from-emerald-600 via-emerald-700 to-teal-700 px-3 xs:px-4 sm:px-6 lg:px-8 py-3 xs:py-4 sm:py-5">
                                <div class="absolute inset-0 opacity-10 pointer-events-none">
                                    <div class="absolute top-0 right-0 w-48 sm:w-64 h-48 sm:h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    <div class="absolute bottom-0 left-0 w-32 sm:w-48 h-32 sm:h-48 bg-white rounded-full blur-3xl translate-y-1/3 -translate-x-1/3"></div>
                                </div>
                                <div class="relative flex items-center gap-2.5 sm:gap-3">
                                    <div class="p-1.5 sm:p-2 bg-white/20 rounded-lg sm:rounded-xl backdrop-blur-sm flex-shrink-0">
                                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-sm xs:text-base sm:text-lg font-bold text-white truncate">Academic Information</h2>
                                        <p class="text-[10px] xs:text-xs sm:text-sm text-emerald-100/90 truncate">Student's academic details</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3.5 xs:gap-4 sm:gap-5 md:gap-6 p-3 xs:p-4 sm:p-6 lg:p-8 sm:grid-cols-2 lg:grid-cols-3">
                                <!-- Academic Year -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Academic Year
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-emerald-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                        <select
                                            v-model="form.academicYear"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 appearance-none focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 cursor-pointer"
                                        >
                                            <option
                                                v-for="year in academicYears"
                                                :key="year"
                                                :value="year"
                                            >
                                                {{ year }}
                                            </option>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-3 sm:pr-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.academicYear" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.academicYear }}
                                    </p>
                                </div>

                                <!-- Class -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Class
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-emerald-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 014 17V5a2 2 0 012-2h14v14H6.5A2.5 2.5 0 004 19.5z" />
                                            </svg>
                                        </div>
                                        <select
                                            v-model="form.class"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 appearance-none focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 cursor-pointer"
                                        >
                                            <option value="">Select Class</option>
                                            <option
                                                v-for="className in availableClasses"
                                                :key="className"
                                                :value="className"
                                            >
                                                {{ className }}
                                            </option>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-3 sm:pr-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.class" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.class }}
                                    </p>
                                </div>

                                <!-- Section -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Section
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-emerald-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                            </svg>
                                        </div>
                                        <select
                                            v-model="form.section"
                                            :disabled="!form.class || availableSections.length === 0"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 appearance-none focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <option value="">Select Section</option>
                                            <option
                                                v-for="section in availableSections"
                                                :key="section"
                                                :value="section"
                                            >
                                                {{ section }}
                                            </option>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-3 sm:pr-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.section" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.section }}
                                    </p>
                                </div>

                                <!-- Roll Number -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Roll Number
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-emerald-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.rollNumber"
                                            type="number"
                                            placeholder="Enter roll number"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                </div>

                                <!-- Admission Date -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Admission Date
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-emerald-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.admissionDate"
                                            type="date"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                    <p v-if="form.errors.admissionDate" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.admissionDate }}
                                    </p>
                                </div>

                                <!-- Status -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Status
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-emerald-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <select
                                            v-model="form.status"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 appearance-none focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-emerald-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 cursor-pointer"
                                        >
                                            <option value="active" class="text-emerald-600 font-medium">🟢 Active</option>
                                            <option value="inactive" class="text-rose-600 font-medium">🔴 Inactive</option>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-3 sm:pr-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 3: Parent / Guardian Information -->
                        <div class="w-full overflow-hidden rounded-xl sm:rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-white/50 dark:border-slate-800 shadow-xl shadow-slate-200/50 dark:shadow-black/40 transition-colors duration-300">
                            <div class="relative overflow-hidden bg-gradient-to-r from-violet-600 via-violet-700 to-purple-700 px-3 xs:px-4 sm:px-6 lg:px-8 py-3 xs:py-4 sm:py-5">
                                <div class="absolute inset-0 opacity-10 pointer-events-none">
                                    <div class="absolute top-0 right-0 w-48 sm:w-64 h-48 sm:h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    <div class="absolute bottom-0 left-0 w-32 sm:w-48 h-32 sm:h-48 bg-white rounded-full blur-3xl translate-y-1/3 -translate-x-1/3"></div>
                                </div>
                                <div class="relative flex items-center gap-2.5 sm:gap-3">
                                    <div class="p-1.5 sm:p-2 bg-white/20 rounded-lg sm:rounded-xl backdrop-blur-sm flex-shrink-0">
                                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-sm xs:text-base sm:text-lg font-bold text-white truncate">Parent / Guardian Information</h2>
                                        <p class="text-[10px] xs:text-xs sm:text-sm text-violet-100/90 truncate">Parent and guardian contact details</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3.5 xs:gap-4 sm:gap-5 md:gap-6 p-3 xs:p-4 sm:p-6 lg:p-8 sm:grid-cols-2">
                                <!-- Father's Name -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Father's Name
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.fatherName"
                                            type="text"
                                            placeholder="Enter father's full name"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                    <p v-if="form.errors.fatherName" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.fatherName }}
                                    </p>
                                </div>

                                <!-- Father's Phone -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Father's Phone
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.fatherPhone"
                                            type="tel"
                                            placeholder="Enter father's phone number"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                    <p v-if="form.errors.fatherPhone" class="mt-1 text-xs sm:text-sm text-rose-600 dark:text-rose-400">
                                        {{ form.errors.fatherPhone }}
                                    </p>
                                </div>

                                <!-- Mother's Name -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Mother's Name
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.motherName"
                                            type="text"
                                            placeholder="Enter mother's full name"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                </div>

                                <!-- Mother's Phone -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Mother's Phone
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.motherPhone"
                                            type="tel"
                                            placeholder="Enter mother's phone number"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                </div>

                                <!-- Guardian Name -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Guardian Name
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.guardianName"
                                            type="text"
                                            placeholder="Enter guardian's full name"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                </div>

                                <!-- Guardian Phone -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Guardian Phone
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="form.guardianPhone"
                                            type="tel"
                                            placeholder="Enter guardian's phone number"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600"
                                        />
                                    </div>
                                </div>

                                <!-- Guardian Relation -->
                                <div class="sm:col-span-2">
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Guardian Relation
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-violet-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                            </svg>
                                        </div>
                                        <select
                                            v-model="form.guardianRelation"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 appearance-none focus:border-violet-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-violet-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 cursor-pointer"
                                        >
                                            <option value="">Select Relation</option>
                                            <option
                                                v-for="relation in guardianRelations"
                                                :key="relation"
                                                :value="relation"
                                            >
                                                {{ relation }}
                                            </option>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-3 sm:pr-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 4: Address -->
                        <div class="w-full overflow-hidden rounded-xl sm:rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-white/50 dark:border-slate-800 shadow-xl shadow-slate-200/50 dark:shadow-black/40 transition-colors duration-300">
                            <div class="relative overflow-hidden bg-gradient-to-r from-teal-600 via-teal-700 to-cyan-700 px-3 xs:px-4 sm:px-6 lg:px-8 py-3 xs:py-4 sm:py-5">
                                <div class="absolute inset-0 opacity-10 pointer-events-none">
                                    <div class="absolute top-0 right-0 w-48 sm:w-64 h-48 sm:h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    <div class="absolute bottom-0 left-0 w-32 sm:w-48 h-32 sm:h-48 bg-white rounded-full blur-3xl translate-y-1/3 -translate-x-1/3"></div>
                                </div>
                                <div class="relative flex items-center gap-2.5 sm:gap-3">
                                    <div class="p-1.5 sm:p-2 bg-white/20 rounded-lg sm:rounded-xl backdrop-blur-sm flex-shrink-0">
                                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-sm xs:text-base sm:text-lg font-bold text-white truncate">Address</h2>
                                        <p class="text-[10px] xs:text-xs sm:text-sm text-teal-100/90 truncate">Student's address details</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3.5 xs:gap-4 sm:gap-5 md:gap-6 p-3 xs:p-4 sm:p-6 lg:p-8 sm:grid-cols-2">
                                <!-- Present Address -->
                                <div>
                                    <label class="mb-1.5 sm:mb-2.5 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Present Address
                                    </label>
                                    <div class="relative group">
                                        <div class="absolute top-2.5 sm:top-3 left-0 pl-3 sm:pl-3.5 flex items-start pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-teal-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                        </div>
                                        <textarea
                                            v-model="form.presentAddress"
                                            rows="3"
                                            placeholder="Enter present address"
                                            class="w-full rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-teal-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-teal-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600 resize-none"
                                        ></textarea>
                                    </div>
                                </div>

                                <!-- Permanent Address -->
                                <div>
                                    <div class="flex flex-col xs:flex-row xs:items-center xs:justify-between gap-1.5 xs:gap-0 mb-1.5 sm:mb-2.5">
                                        <label class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                            Permanent Address
                                        </label>
                                        <label
                                            class="flex items-center gap-1.5 sm:gap-2 text-[11px] sm:text-sm font-medium cursor-pointer transition-all duration-200"
                                            :class="[
                                                sameAsPresent
                                                    ? 'text-blue-600 dark:text-blue-400'
                                                    : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
                                            ]"
                                        >
                                            <div class="relative flex items-center">
                                                <input
                                                    v-model="sameAsPresent"
                                                    @change="copyPresentToPermanent"
                                                    type="checkbox"
                                                    class="w-3.5 h-3.5 sm:w-4 sm:h-4 rounded border-2 transition-all duration-200 cursor-pointer"
                                                    :class="[
                                                        sameAsPresent
                                                            ? 'border-blue-500 bg-blue-500 ring-2 ring-blue-200 dark:ring-blue-900'
                                                            : 'border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:border-blue-400'
                                                    ]"
                                                />
                                                <svg
                                                    v-if="sameAsPresent"
                                                    class="absolute w-2.5 h-2.5 sm:w-3 sm:h-3 text-white pointer-events-none left-0.5 top-0.5"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </div>
                                            <span :class="[
                                                'transition-colors duration-200',
                                                sameAsPresent ? 'text-blue-600 dark:text-blue-400 font-semibold' : 'text-slate-500 dark:text-slate-400'
                                            ]">
                                                Same as Present
                                            </span>
                                            <span
                                                v-if="sameAsPresent"
                                                class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] sm:text-[10px] font-bold bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 animate-pulse"
                                            >
                                                Auto
                                            </span>
                                        </label>
                                    </div>
                                    <div class="relative group">
                                        <div class="absolute top-2.5 sm:top-3 left-0 pl-3 sm:pl-3.5 flex items-start pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-teal-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                        <textarea
                                            v-model="form.permanentAddress"
                                            rows="3"
                                            placeholder="Enter permanent address"
                                            :class="[
                                                'w-full rounded-lg sm:rounded-xl border-2 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-2.5 lg:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 resize-none',
                                                sameAsPresent
                                                    ? 'border-blue-300 dark:border-blue-800 bg-blue-50/30 dark:bg-blue-950/20 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10'
                                                    : 'border-slate-200 dark:border-slate-700 focus:border-teal-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-teal-500/10 group-hover:border-slate-300 dark:group-hover:border-slate-600'
                                            ]"
                                        ></textarea>
                                    </div>
                                    <div
                                        v-if="sameAsPresent"
                                        class="mt-1.5 flex items-center gap-1.5 text-[10px] sm:text-xs text-blue-600 dark:text-blue-400 flex-wrap"
                                    >
                                        <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5 animate-bounce flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                                        </svg>
                                        <span>Auto-synced with Present Address</span>
                                        <span class="text-[9px] sm:text-[10px] text-blue-400 dark:text-blue-500">(uncheck to edit manually)</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- FORM ACTIONS -->
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 sm:gap-4 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl rounded-xl sm:rounded-2xl border border-white/50 dark:border-slate-800 px-3 xs:px-4 sm:px-6 lg:px-8 py-3.5 xs:py-4 sm:py-5 shadow-xl shadow-slate-200/50 dark:shadow-black/40 transition-colors duration-300">
                            <div class="flex items-center gap-1.5 sm:gap-2 text-[10px] xs:text-xs text-slate-400 dark:text-slate-500 justify-center sm:justify-start">
                                <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>All fields marked with <span class="text-rose-500 font-bold">*</span> are required</span>
                            </div>

                            <div class="flex flex-col xs:flex-row items-stretch xs:items-center gap-2 sm:gap-3 w-full sm:w-auto">
                                <button
                                    type="reset"
                                    class="w-full xs:w-auto rounded-lg sm:rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-800/80 px-4 sm:px-6 py-2.5 text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 transition-all duration-300 hover:bg-white dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-lg active:scale-95"
                                >
                                    Reset Form
                                </button>

                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="w-full xs:w-auto inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-lg sm:rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 sm:px-6 py-2.5 text-xs sm:text-sm font-medium text-white transition-all duration-300 hover:from-blue-700 hover:to-indigo-700 hover:shadow-lg hover:shadow-blue-500/25 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <svg v-if="!form.processing" class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                    <svg v-else class="w-4 h-4 sm:w-5 sm:h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ form.processing ? 'Creating...' : 'Create Student' }}
                                </button>
                            </div>
                        </div>

                    </div>
                </form>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

.animate-bounce {
    animation: bounce 1s infinite;
}

@keyframes bounce {
    0%, 100% {
        transform: translateX(0);
    }
    50% {
        transform: translateX(4px);
    }
}
</style>