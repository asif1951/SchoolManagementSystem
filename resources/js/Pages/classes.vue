<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';


// =====================================================
// PROPS
// =====================================================

const props = defineProps({
    teachers: {
        type: Array,
        default: () => [],
    },
});


// =====================================================
// FORM
// =====================================================

const form = useForm({
    className: '',
    section: '',
    academicYear: '',
    classTeacher: '',
    status: 'active',
    subjects: [],
    maxStudents: null,
    description: '',
});


// =====================================================
// TOAST
// =====================================================

const toast = ref({
    show: false,
    message: '',
    type: 'success',
    timeout: null,
});

const showToast = (message, type = 'success') => {
    if (toast.value.timeout) {
        clearTimeout(toast.value.timeout);
    }

    toast.value.message = message;
    toast.value.type = type;
    toast.value.show = true;

    toast.value.timeout = setTimeout(() => {
        toast.value.show = false;
    }, 3000);
};

const hideToast = () => {
    toast.value.show = false;

    if (toast.value.timeout) {
        clearTimeout(toast.value.timeout);
        toast.value.timeout = null;
    }
};

const getToastStyles = () => {
    const styles = {
        success: {
            bg: 'bg-green-50 dark:bg-green-900/30',
            border: 'border-green-400 dark:border-green-600',
            text: 'text-green-800 dark:text-green-300',
            icon: 'text-green-400 dark:text-green-300',
        },

        error: {
            bg: 'bg-red-50 dark:bg-red-900/30',
            border: 'border-red-400 dark:border-red-600',
            text: 'text-red-800 dark:text-red-300',
            icon: 'text-red-400 dark:text-red-300',
        },

        warning: {
            bg: 'bg-yellow-50 dark:bg-yellow-900/30',
            border: 'border-yellow-400 dark:border-yellow-600',
            text: 'text-yellow-800 dark:text-yellow-300',
            icon: 'text-yellow-400 dark:text-yellow-300',
        },
    };

    return styles[toast.value.type] || styles.success;
};


// =====================================================
// TEACHER SEARCH / SUGGESTIONS
// =====================================================

const teacherSearchQuery = ref('');
const filteredTeachers = ref([]);
const showTeacherSuggestions = ref(false);
const selectedTeacherName = ref('');

// Filter teachers based on search query
watch(teacherSearchQuery, (value) => {
    if (value && value.trim()) {
        const search = value.toLowerCase().trim();
        
        filteredTeachers.value = props.teachers.filter(teacher =>
            teacher.name.toLowerCase().includes(search) ||
            teacher.email.toLowerCase().includes(search)
        );
        
        showTeacherSuggestions.value = filteredTeachers.value.length > 0;
    } else {
        showTeacherSuggestions.value = false;
        filteredTeachers.value = [];
    }
});

// Select a teacher from suggestions
const selectTeacher = (teacher) => {
    form.classTeacher = teacher.id;
    selectedTeacherName.value = teacher.name;
    teacherSearchQuery.value = `${teacher.name} (${teacher.email})`;
    showTeacherSuggestions.value = false;
    filteredTeachers.value = [];
};

// Clear teacher selection
const clearTeacher = () => {
    form.classTeacher = '';
    selectedTeacherName.value = '';
    teacherSearchQuery.value = '';
    showTeacherSuggestions.value = false;
    filteredTeachers.value = [];
};

// Close suggestions
const closeTeacherSuggestions = () => {
    setTimeout(() => {
        showTeacherSuggestions.value = false;
    }, 200);
};

// Get selected teacher name for display
const getSelectedTeacherName = computed(() => {
    if (form.classTeacher) {
        const teacher = props.teachers.find(t => t.id === form.classTeacher);
        return teacher ? `${teacher.name} (${teacher.email})` : '';
    }
    return '';
});

// Watch for form.classTeacher changes
watch(() => form.classTeacher, (value) => {
    if (value) {
        const teacher = props.teachers.find(t => t.id === value);
        if (teacher) {
            selectedTeacherName.value = teacher.name;
            teacherSearchQuery.value = `${teacher.name} (${teacher.email})`;
        }
    } else {
        selectedTeacherName.value = '';
        teacherSearchQuery.value = '';
    }
});


// =====================================================
// SUBJECTS
// =====================================================

const availableSubjects = [
    { id: 1, name: 'Bangla', icon: '📖' },
    { id: 2, name: 'English', icon: '🔤' },
    { id: 3, name: 'Mathematics', icon: '📐' },
    { id: 4, name: 'Science', icon: '🔬' },
    { id: 5, name: 'Bangladesh & Global Studies', icon: '🌍' },
    { id: 6, name: 'Religion', icon: '🕌' },
    { id: 7, name: 'ICT', icon: '💻' },
];

const newSubject = ref('');
const showSubjectInput = ref(false);

const selectedSubjects = ref([]);

const toggleSubject = (subject) => {
    const index = selectedSubjects.value.findIndex(
        item => item.id === subject.id
    );

    if (index > -1) {
        selectedSubjects.value.splice(index, 1);
    } else {
        selectedSubjects.value.push(subject);
    }

    form.subjects = selectedSubjects.value.map(
        subject => subject.name
    );
};

const addCustomSubject = () => {
    const subjectName = newSubject.value.trim();

    if (!subjectName) {
        return;
    }

    const alreadyExists = selectedSubjects.value.some(
        subject =>
            subject.name.toLowerCase() === subjectName.toLowerCase()
    );

    if (alreadyExists) {
        showToast('This subject is already selected.', 'warning');
        return;
    }

    const customSubject = {
        id: Date.now(),
        name: subjectName,
        icon: '📚',
        custom: true,
    };

    selectedSubjects.value.push(customSubject);

    form.subjects = selectedSubjects.value.map(
        subject => subject.name
    );

    newSubject.value = '';
    showSubjectInput.value = false;
};

const removeSubject = (subjectId) => {
    selectedSubjects.value = selectedSubjects.value.filter(
        subject => subject.id !== subjectId
    );

    form.subjects = selectedSubjects.value.map(
        subject => subject.name
    );
};

const isSelected = (subject) => {
    return selectedSubjects.value.some(
        selected => selected.id === subject.id
    );
};

const selectedCount = computed(() => {
    return selectedSubjects.value.length;
});


// =====================================================
// SECTION SUGGESTIONS - FIXED
// =====================================================

const sectionSuggestions = [
    'A',
    'B',
    'C',
    'D',
    'E',
    'F',
    'G',
    'H',
];

const filteredSections = ref([]);
const showSectionSuggestions = ref(false);

watch(
    () => form.section,
    (value) => {
        if (value && value.trim()) {
            const searchValue = value.toLowerCase().trim();

            const matched = sectionSuggestions.filter(section =>
                section.toLowerCase().includes(searchValue)
            );

            // User typed suggestion - label এ "Section" দেখাবে, value তে শুধু নাম
            const userSuggestion = {
                label: `Section ${value}`,
                value: value,
                isUserTyped: true,
            };

            // Matched suggestions - label এ "Section" দেখাবে, value তে শুধু নাম
            const matchedSuggestions = matched.map(section => ({
                label: `Section ${section}`,
                value: section,
                isUserTyped: false,
            }));

            const suggestions = [
                userSuggestion,
                ...matchedSuggestions,
            ];

            const uniqueSuggestions = suggestions.filter(
                (item, index, array) =>
                    array.findIndex(
                        suggestion => suggestion.value === item.value
                    ) === index
            );

            filteredSections.value = uniqueSuggestions;
            showSectionSuggestions.value = true;
        } else {
            showSectionSuggestions.value = false;
            filteredSections.value = [];
        }
    }
);

// Select section - সরাসরি value সেট করব, "Section" prefix ছাড়া
const selectSection = (sectionValue) => {
    form.section = sectionValue;

    showSectionSuggestions.value = false;
    filteredSections.value = [];
};


// =====================================================
// ACADEMIC YEAR
// =====================================================

const currentYear = new Date().getFullYear();

const yearSuggestions = [];

for (let i = 0; i < 5; i++) {
    yearSuggestions.push(String(currentYear + i));
}

const filteredYears = ref([]);
const showYearSuggestions = ref(false);

watch(
    () => form.academicYear,
    (value) => {
        if (value) {
            filteredYears.value = yearSuggestions.filter(year =>
                year.includes(value)
            );

            showYearSuggestions.value =
                filteredYears.value.length > 0;
        } else {
            showYearSuggestions.value = false;
            filteredYears.value = [];
        }
    }
);

const selectYear = (year) => {
    form.academicYear = year;

    showYearSuggestions.value = false;
    filteredYears.value = [];
};


// =====================================================
// CLOSE SUGGESTIONS
// =====================================================

const closeSuggestions = () => {
    setTimeout(() => {
        showSectionSuggestions.value = false;
        showYearSuggestions.value = false;
    }, 200);
};


// =====================================================
// RESET
// =====================================================

const resetForm = () => {
    form.reset();

    selectedSubjects.value = [];

    form.subjects = [];

    newSubject.value = '';

    showSubjectInput.value = false;

    form.academicYear = String(currentYear);

    filteredSections.value = [];
    showSectionSuggestions.value = false;

    filteredYears.value = [];
    showYearSuggestions.value = false;

    // Reset teacher
    clearTeacher();
};


// =====================================================
// SUBMIT
// =====================================================

const submitForm = () => {
    form.post('/classes', {
        preserveScroll: true,

        onSuccess: () => {
            showToast(
                'Class created successfully! 🎉',
                'success'
            );

            resetForm();

            setTimeout(() => {
                router.visit('/classes');
            }, 1500);
        },

        onError: () => {
            showToast(
                'Please fix the validation errors.',
                'error'
            );
        },
    });
};


// =====================================================
// MOUNT
// =====================================================

onMounted(() => {
    form.academicYear = String(currentYear);

    console.log('Teachers received from Laravel:', props.teachers);
});
</script>

<template>
    <Head title="Create Class" />

    <AuthenticatedLayout>
        <div class="w-full min-h-screen bg-gradient-to-br from-slate-50 via-blue-50/20 to-indigo-50/20 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 px-3 py-4 sm:px-4 sm:py-6 lg:px-6 xl:px-8 relative transition-colors duration-300">

            <!-- Toast Notification -->
            <transition
                enter-active-class="transform transition-all duration-500 ease-in-out"
                enter-from-class="translate-x-full opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transform transition-all duration-500 ease-in-out"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-full opacity-0"
            >
                <div
                    v-if="toast.show"
                    class="fixed top-4 right-4 left-4 sm:left-auto z-[100] max-w-xs sm:max-w-md w-full mx-auto sm:mx-0"
                >
                    <div
                        :class="[
                            'rounded-2xl shadow-2xl border p-3 sm:p-4 backdrop-blur-xl',
                            getToastStyles().bg,
                            getToastStyles().border
                        ]"
                    >
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                <svg v-if="toast.type === 'success'" class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <svg v-else-if="toast.type === 'error'" class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <svg v-else class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p :class="['text-sm font-medium break-words', getToastStyles().text]">
                                    {{ toast.message }}
                                </p>
                            </div>
                            <button @click="hideToast" class="flex-shrink-0 -mt-1 -mr-1 p-1 hover:bg-black/10 dark:hover:bg-white/10 rounded-xl transition-colors">
                                <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="mt-2 h-1 bg-black/10 dark:bg-white/10 rounded-full overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-3000 ease-linear"
                                :class="{
                                    'bg-green-500': toast.type === 'success',
                                    'bg-red-500': toast.type === 'error',
                                    'bg-yellow-500': toast.type === 'warning'
                                }"
                                :style="{ width: toast.show ? '100%' : '0%' }"
                            ></div>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- Page Header -->
            <div class="mb-5 sm:mb-6 lg:mb-8">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-4">
                    <div class="flex items-center gap-3 sm:gap-4 w-full sm:w-auto">
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl shadow-lg shadow-blue-500/20 ring-1 ring-white/20 flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 lg:w-7 lg:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 dark:from-slate-100 dark:to-slate-300 bg-clip-text text-transparent truncate">
                                Create New Class
                            </h1>
                            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 flex items-center gap-2 mt-0.5 sm:mt-1">
                                <span class="inline-block w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                <span class="hidden xs:inline">Set up a new class with teacher assignment and subjects</span>
                                <span class="xs:hidden">Set up a new class</span>
                            </p>
                        </div>
                    </div>

                    <a
                        href="/classes"
                        class="group inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm px-4 py-2 sm:px-5 sm:py-2.5 text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 transition-all duration-300 hover:bg-white dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-lg hover:-translate-x-1 w-full sm:w-auto"
                    >
                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span class="hidden xs:inline">Back to Classes</span>
                        <span class="xs:hidden">Back</span>
                    </a>
                </div>
            </div>

            <!-- Main Card -->
            <div class="w-full overflow-hidden rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-white/50 dark:border-slate-800 shadow-2xl shadow-slate-200/50 dark:shadow-black/40 transition-colors duration-300">

                <!-- Card Header -->
                <div class="relative overflow-hidden bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 px-4 sm:px-6 lg:px-8 py-4 sm:py-5 lg:py-6">
                    <div class="absolute inset-0 opacity-10">
                        <div class="absolute top-0 right-0 w-48 sm:w-64 h-48 sm:h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                        <div class="absolute bottom-0 left-0 w-36 sm:w-48 h-36 sm:h-48 bg-white rounded-full blur-3xl translate-y-1/3 -translate-x-1/3"></div>
                    </div>

                    <div class="relative flex flex-col xs:flex-row items-start xs:items-center justify-between gap-3 xs:gap-4">
                        <div>
                            <h2 class="text-base sm:text-lg lg:text-xl font-bold text-white flex items-center gap-2 sm:gap-3">
                                <div class="p-1 bg-white/20 rounded-lg backdrop-blur-sm">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <span class="hidden xs:inline">Class Information</span>
                                <span class="xs:hidden">Class Info</span>
                            </h2>
                            <p class="mt-0.5 sm:mt-1 text-xs sm:text-sm text-blue-100/90 hidden xs:block">
                                Fill in the details below to create a new class
                            </p>
                        </div>

                        <div class="flex items-center gap-2 sm:gap-3 flex-wrap">
                            <span class="inline-flex items-center gap-1.5 sm:gap-2 px-2 sm:px-3 py-1 sm:py-1.5 bg-white/20 rounded-full backdrop-blur-sm text-[10px] sm:text-xs font-medium text-white border border-white/20">
                                <span class="w-1.5 sm:w-2 h-1.5 sm:h-2 rounded-full bg-green-400 animate-pulse"></span>
                                <span class="hidden xs:inline">Form Active</span>
                                <span class="xs:hidden">Active</span>
                            </span>

                            <span
                                v-if="selectedCount > 0"
                                class="inline-flex items-center gap-1 sm:gap-1.5 px-2 sm:px-3 py-1 sm:py-1.5 bg-white/20 rounded-full backdrop-blur-sm text-[10px] sm:text-xs font-medium text-white border border-white/20"
                            >
                                <span class="font-bold">{{ selectedCount }}</span>
                                <span class="hidden xs:inline">{{ selectedCount === 1 ? 'Subject' : 'Subjects' }} Selected</span>
                                <span class="xs:hidden">Selected</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <form @submit.prevent="submitForm" class="divide-y divide-slate-200/60 dark:divide-slate-800">

                    <div class="grid grid-cols-1 gap-4 sm:gap-5 lg:gap-6 p-4 sm:p-6 lg:p-8 md:grid-cols-2">
                        <!-- Class Name -->
                        <div class="md:col-span-2 lg:col-span-1">
                            <label class="mb-1.5 sm:mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Class Name <span class="text-red-500 ml-0.5">*</span>
                            </label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-blue-500 transition-colors duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11a4 4 0 100-8 4 4 0 000 8z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="form.className"
                                    type="text"
                                    placeholder="e.g. Class 1, Class 5, Class 10"
                                    class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2.5 sm:py-3 lg:py-3.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 hover:border-slate-300 dark:hover:border-slate-600"
                                    :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500/10': form.errors.className }"
                                />
                            </div>
                            <p v-if="form.errors.className" class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ form.errors.className }}</p>
                            <p v-else class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Enter a unique name for the class</p>
                        </div>

                        <!-- Section -->
                        <div>
                            <label class="mb-1.5 sm:mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Section <span class="text-slate-400 dark:text-slate-500 ml-1 text-[10px] sm:text-xs font-normal">(Optional)</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none z-10">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="form.section"
                                    type="text"
                                    placeholder="e.g. A, B, C or custom"
                                    class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2.5 sm:py-3 lg:py-3.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-purple-500/10 hover:border-slate-300 dark:hover:border-slate-600"
                                    :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500/10': form.errors.section }"
                                    @focus="showSectionSuggestions = true"
                                    @blur="closeSuggestions"
                                />
                                <div
                                    v-if="showSectionSuggestions && filteredSections.length > 0"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 border-2 border-slate-200 dark:border-slate-700 rounded-xl shadow-lg max-h-48 overflow-y-auto"
                                >
                                    <button
                                        v-for="item in filteredSections"
                                        :key="item.value"
                                        type="button"
                                        @mousedown.prevent="selectSection(item.value)"
                                        class="w-full text-left px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-200 hover:bg-purple-50 dark:hover:bg-purple-950/30 hover:text-purple-700 dark:hover:text-purple-300 transition-colors flex items-center gap-2 border-b border-slate-100 dark:border-slate-700 last:border-b-0"
                                        :class="{ 'bg-purple-50/50 dark:bg-purple-950/40 font-semibold': item.isUserTyped }"
                                    >
                                        <span class="w-5 h-5 sm:w-6 sm:h-6 rounded-full bg-purple-100 dark:bg-purple-900/50 text-purple-600 dark:text-purple-300 flex items-center justify-center text-xs font-bold flex-shrink-0">
                                            {{ item.value.charAt(0).toUpperCase() }}
                                        </span>
                                        <span>{{ item.label }}</span>
                                        <span v-if="item.isUserTyped" class="ml-auto text-[10px] sm:text-xs text-purple-500 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/40 px-1.5 sm:px-2 py-0.5 rounded-full">
                                            New
                                        </span>
                                    </button>
                                </div>
                            </div>
                            <p v-if="form.errors.section" class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ form.errors.section }}</p>
                            <p v-else class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Type any section name - suggestions appear automatically</p>
                        </div>

                        <!-- Academic Year -->
                        <div>
                            <label class="mb-1.5 sm:mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Academic Year <span class="text-red-500 ml-0.5">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none z-10">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="form.academicYear"
                                    type="text"
                                    placeholder="e.g. 2026"
                                    class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2.5 sm:py-3 lg:py-3.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-green-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-green-500/10 hover:border-slate-300 dark:hover:border-slate-600"
                                    :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500/10': form.errors.academicYear }"
                                    @focus="showYearSuggestions = true"
                                    @blur="closeSuggestions"
                                />
                                <div
                                    v-if="showYearSuggestions && filteredYears.length > 0"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 border-2 border-slate-200 dark:border-slate-700 rounded-xl shadow-lg max-h-40 overflow-y-auto"
                                >
                                    <button
                                        v-for="year in filteredYears"
                                        :key="year"
                                        type="button"
                                        @mousedown.prevent="selectYear(year)"
                                        class="w-full text-left px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-200 hover:bg-green-50 dark:hover:bg-green-950/30 hover:text-green-700 dark:hover:text-green-300 transition-colors flex items-center gap-2 border-b border-slate-100 dark:border-slate-700 last:border-b-0"
                                    >
                                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        {{ year }}
                                    </button>
                                </div>
                            </div>
                            <p v-if="form.errors.academicYear" class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ form.errors.academicYear }}</p>
                            <p v-else class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Type year or select from suggestions</p>
                        </div>

                        <!-- Class Teacher -->
                        <div>
                            <label class="mb-1.5 sm:mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Class Teacher <span class="text-red-500 ml-0.5">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none z-10">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="teacherSearchQuery"
                                    type="text"
                                    placeholder="Type teacher name or email..."
                                    class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-8 sm:pr-10 py-2.5 sm:py-3 lg:py-3.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-orange-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-orange-500/10 hover:border-slate-300 dark:hover:border-slate-600"
                                    @focus="showTeacherSuggestions = true"
                                    @blur="closeTeacherSuggestions"
                                />
                                <button
                                    v-if="teacherSearchQuery"
                                    type="button"
                                    @click="clearTeacher"
                                    class="absolute inset-y-0 right-0 pr-2.5 sm:pr-3.5 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                                >
                                    <svg class="w-3.5 h-3.5 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <div
                                    v-if="showTeacherSuggestions && filteredTeachers.length > 0"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 border-2 border-slate-200 dark:border-slate-700 rounded-xl shadow-lg max-h-48 overflow-y-auto"
                                >
                                    <button
                                        v-for="teacher in filteredTeachers"
                                        :key="teacher.id"
                                        type="button"
                                        @mousedown.prevent="selectTeacher(teacher)"
                                        class="w-full text-left px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-200 hover:bg-orange-50 dark:hover:bg-orange-950/30 hover:text-orange-700 dark:hover:text-orange-300 transition-colors flex items-center gap-2 sm:gap-3 border-b border-slate-100 dark:border-slate-700 last:border-b-0"
                                    >
                                        <div class="w-6 h-6 sm:w-8 sm:h-8 rounded-full bg-orange-100 dark:bg-orange-900/40 text-orange-600 dark:text-orange-300 flex items-center justify-center text-xs font-bold flex-shrink-0">
                                            {{ teacher.name.charAt(0).toUpperCase() }}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs sm:text-sm font-medium truncate">{{ teacher.name }}</div>
                                            <div class="text-[10px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">{{ teacher.email }}</div>
                                        </div>
                                    </button>
                                </div>
                                <div
                                    v-else-if="showTeacherSuggestions && filteredTeachers.length === 0 && teacherSearchQuery"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 border-2 border-slate-200 dark:border-slate-700 rounded-xl shadow-lg p-3 sm:p-4 text-center text-xs sm:text-sm text-slate-500 dark:text-slate-400"
                                >
                                    No teachers found matching "{{ teacherSearchQuery }}"
                                </div>
                            </div>
                            <input type="hidden" v-model="form.classTeacher" />
                            <p v-if="form.errors.classTeacher" class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ form.errors.classTeacher }}</p>
                            <p v-else-if="props.teachers.length === 0" class="mt-1.5 text-xs text-red-500 dark:text-red-400">No active teachers found. Please add teachers first.</p>
                            <p v-else-if="!form.classTeacher && !teacherSearchQuery" class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Type teacher name or email to search</p>
                            <p v-else-if="form.classTeacher" class="mt-1.5 text-xs text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                Teacher selected
                            </p>
                        </div>

                        <!-- Max Students -->
                        <div>
                            <label class="mb-1.5 sm:mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Maximum Students <span class="text-slate-400 dark:text-slate-500 ml-1 text-[10px] sm:text-xs font-normal">(Optional)</span>
                            </label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-teal-500 transition-colors duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                </div>
                                <input
                                    v-model.number="form.maxStudents"
                                    type="number"
                                    min="1"
                                    max="200"
                                    placeholder="e.g. 40"
                                    class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2.5 sm:py-3 lg:py-3.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-teal-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-teal-500/10 hover:border-slate-300 dark:hover:border-slate-600"
                                    :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500/10': form.errors.maxStudents }"
                                />
                            </div>
                            <p v-if="form.errors.maxStudents" class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ form.errors.maxStudents }}</p>
                            <p v-else class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Set max student capacity (optional)</p>
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="mb-1.5 sm:mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Status
                            </label>
                            <div class="flex items-center gap-2 sm:gap-4 p-2 sm:p-3 bg-slate-50/50 dark:bg-slate-800/50 rounded-xl border-2 border-slate-200 dark:border-slate-700">
                                <button
                                    type="button"
                                    @click="form.status = 'active'"
                                    class="flex-1 flex items-center justify-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-[10px] sm:text-sm font-medium transition-all duration-300"
                                    :class="form.status === 'active' ? 'bg-green-500 text-white shadow-lg shadow-green-500/30 scale-[1.02]' : 'bg-white/50 dark:bg-slate-700/50 text-slate-600 dark:text-slate-300 hover:bg-green-50 dark:hover:bg-green-950/40 hover:scale-[1.02]'"
                                >
                                    <span class="text-base sm:text-lg">🟢</span>
                                    <span>Active</span>
                                </button>
                                <button
                                    type="button"
                                    @click="form.status = 'inactive'"
                                    class="flex-1 flex items-center justify-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-[10px] sm:text-sm font-medium transition-all duration-300"
                                    :class="form.status === 'inactive' ? 'bg-red-500 text-white shadow-lg shadow-red-500/30 scale-[1.02]' : 'bg-white/50 dark:bg-slate-700/50 text-slate-600 dark:text-slate-300 hover:bg-red-50 dark:hover:bg-red-950/40 hover:scale-[1.02]'"
                                >
                                    <span class="text-base sm:text-lg">🔴</span>
                                    <span>Inactive</span>
                                </button>
                            </div>
                            <p class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Set the class status</p>
                        </div>

                        <!-- Description -->
                        <div class="md:col-span-2">
                            <label class="mb-1.5 sm:mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Description <span class="text-slate-400 dark:text-slate-500 ml-1 text-[10px] sm:text-xs font-normal">(Optional)</span>
                            </label>
                            <div class="relative group">
                                <div class="absolute top-3 sm:top-3.5 left-3 sm:left-3.5 flex items-start pointer-events-none">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-blue-500 transition-colors duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7" />
                                    </svg>
                                </div>
                                <textarea
                                    v-model="form.description"
                                    rows="3"
                                    placeholder="Add a short description about this class..."
                                    class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2.5 sm:py-3 lg:py-3.5 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/10 hover:border-slate-300 dark:hover:border-slate-600 resize-none"
                                    :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500/10': form.errors.description }"
                                ></textarea>
                            </div>
                            <p v-if="form.errors.description" class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ form.errors.description }}</p>
                            <p v-else class="mt-1.5 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500">Add any additional information (optional)</p>
                        </div>

                        <!-- Subjects -->
                        <div class="md:col-span-2">
                            <div class="mb-3 sm:mb-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                <div>
                                    <label class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Subjects <span class="text-red-500 ml-0.5">*</span>
                                    </label>
                                    <p class="text-[10px] sm:text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                                        Select or add subjects
                                        <span class="inline-flex items-center gap-1 ml-1.5 sm:ml-2 px-1.5 sm:px-2 py-0.5 bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded-full text-[10px] sm:text-xs font-medium">
                                            {{ selectedCount }} selected
                                        </span>
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    @click="showSubjectInput = !showSubjectInput"
                                    class="inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-3 sm:px-4 py-2 sm:py-2.5 text-[10px] sm:text-sm font-medium text-white transition-all duration-300 hover:from-blue-700 hover:to-indigo-700 hover:shadow-lg hover:shadow-blue-500/25 active:scale-95 w-full sm:w-auto"
                                >
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                    <span class="hidden xs:inline">Add Custom Subject</span>
                                    <span class="xs:hidden">Add Subject</span>
                                </button>
                            </div>

                            <div v-if="showSubjectInput" class="mb-3 sm:mb-4 p-3 sm:p-4 bg-blue-50/50 dark:bg-blue-950/30 rounded-xl border-2 border-blue-200 dark:border-blue-800 animate-fadeIn">
                                <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                                    <div class="flex-1 relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-3.5 flex items-center pointer-events-none">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-400 dark:text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                            </svg>
                                        </div>
                                        <input
                                            v-model="newSubject"
                                            type="text"
                                            placeholder="Type new subject name..."
                                            class="w-full rounded-xl border-2 border-blue-200 dark:border-blue-800 bg-white dark:bg-slate-800 pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 sm:py-3 text-xs sm:text-sm text-slate-700 dark:text-slate-100 outline-none transition-all duration-300 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10"
                                            @keyup.enter="addCustomSubject"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        @click="addCustomSubject"
                                        class="inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-green-600 px-4 sm:px-6 py-2 sm:py-3 text-[10px] sm:text-sm font-medium text-white transition-all duration-300 hover:from-emerald-700 hover:to-green-700 hover:shadow-lg active:scale-95 flex-1 sm:flex-none"
                                    >
                                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                        Add
                                    </button>
                                    <button
                                        type="button"
                                        @click="showSubjectInput = false"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 sm:px-4 py-2 sm:py-3 text-[10px] sm:text-sm font-medium text-slate-600 dark:text-slate-300 transition-all duration-300 hover:bg-slate-50 dark:hover:bg-slate-700 hover:border-slate-300 dark:hover:border-slate-600 active:scale-95 flex-1 sm:flex-none"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>

                            <div class="rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50/30 dark:bg-slate-800/30 p-4 sm:p-6 transition-all duration-300 hover:border-slate-300 dark:hover:border-slate-600">
                                <!-- ✅ FIXED: Selected Subjects chips — full name always visible -->
                                <div v-if="selectedCount > 0" class="mb-3 sm:mb-4 flex flex-wrap gap-1.5 sm:gap-2">
                                    <span
                                        v-for="subject in selectedSubjects"
                                        :key="subject.id"
                                        class="inline-flex items-center gap-1 sm:gap-1.5 px-2 sm:px-3 py-1 sm:py-1.5 bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded-full text-[10px] sm:text-sm font-medium max-w-full"
                                    >
                                        <span class="flex-shrink-0">{{ subject.icon }}</span>
                                        <span class="truncate max-w-[140px] sm:max-w-none">{{ subject.name }}</span>
                                        <button
                                            @click="removeSubject(subject.id)"
                                            type="button"
                                            class="ml-0.5 sm:ml-1 p-0.5 hover:bg-blue-200 dark:hover:bg-blue-800 rounded-full transition-colors flex-shrink-0"
                                        >
                                            <svg class="w-2.5 h-2.5 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </span>
                                </div>

                                <div v-if="selectedCount === 0" class="text-center py-3 sm:py-4 text-slate-400 dark:text-slate-500 text-[10px] sm:text-sm">
                                    No subjects selected yet. Select from the options below or add custom subjects.
                                </div>

                                <!-- Subject Grid -->
                                <div class="grid grid-cols-1 xs:grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                                    <label
                                        v-for="subject in availableSubjects"
                                        :key="subject.id"
                                        class="group flex cursor-pointer items-center gap-2 sm:gap-3 p-2 sm:p-3 rounded-xl border-2 transition-all duration-200 hover:shadow-md min-w-0"
                                        :class="isSelected(subject) ? 'border-blue-500 bg-blue-50/70 dark:bg-blue-950/40 shadow-md ring-2 ring-blue-500/20' : 'border-transparent bg-white/80 dark:bg-slate-800/60 hover:border-blue-300 dark:hover:border-blue-700 hover:bg-blue-50/50 dark:hover:bg-blue-950/30'"
                                    >
                                        <div class="relative flex-shrink-0">
                                            <input
                                                type="checkbox"
                                                :checked="isSelected(subject)"
                                                @change="toggleSubject(subject)"
                                                class="h-3.5 w-3.5 sm:h-4 sm:w-4 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 cursor-pointer"
                                            />
                                            <div v-if="isSelected(subject)" class="absolute -top-1 -right-1">
                                                <svg class="w-3 h-3 sm:w-3.5 sm:h-3.5 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                        </div>
                                        <!-- ✅ FIXED: Always show full subject name -->
                                        <span
                                            class="text-[10px] sm:text-sm font-medium truncate min-w-0 flex-1"
                                            :class="isSelected(subject) ? 'text-blue-700 dark:text-blue-300' : 'text-slate-700 dark:text-slate-200 group-hover:text-blue-700 dark:group-hover:text-blue-300'"
                                        >
                                            {{ subject.icon }} {{ subject.name }}
                                        </span>
                                    </label>
                                </div>

                                <div class="mt-3 sm:mt-4 pt-3 sm:pt-4 border-t-2 border-slate-200/60 dark:border-slate-700/60">
                                    <p class="text-[10px] sm:text-xs flex items-center gap-1.5">
                                        <span class="inline-block w-1 h-1 rounded-full bg-slate-400 dark:bg-slate-500"></span>
                                        <span v-if="selectedCount === 0" class="text-red-500 dark:text-red-400 font-medium">⚠ Select at least one subject for this class</span>
                                        <span v-else class="text-slate-600 dark:text-slate-300">✓ {{ selectedCount }} subject{{ selectedCount > 1 ? 's' : '' }} selected</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 sm:gap-4 px-4 sm:px-6 lg:px-8 py-4 sm:py-5 bg-gradient-to-r from-slate-50/80 to-blue-50/40 dark:from-slate-800/60 dark:to-slate-900/40 transition-colors duration-300">
                        <div class="flex items-center gap-1.5 sm:gap-2 text-[10px] sm:text-xs text-slate-400 dark:text-slate-500 justify-center sm:justify-start">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="hidden xs:inline">All fields marked with <span class="text-red-500 font-bold">*</span> are required</span>
                            <span class="xs:hidden"><span class="text-red-500 font-bold">*</span> required</span>
                        </div>

                        <div class="flex items-center gap-2 sm:gap-3 w-full sm:w-auto">
                            <button
                                type="button"
                                @click="resetForm"
                                class="flex-1 sm:flex-none rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-800/80 px-4 sm:px-6 py-2 sm:py-2.5 text-[10px] sm:text-sm font-medium text-slate-700 dark:text-slate-200 transition-all duration-300 hover:bg-white dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-lg active:scale-95"
                            >
                                Reset
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 sm:px-6 py-2 sm:py-2.5 text-[10px] sm:text-sm font-medium text-white transition-all duration-300 hover:from-blue-700 hover:to-indigo-700 hover:shadow-lg hover:shadow-blue-500/25 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg v-if="!form.processing" class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                <svg v-else class="w-4 h-4 sm:w-5 sm:h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span class="hidden xs:inline">{{ form.processing ? 'Creating...' : 'Create Class' }}</span>
                                <span class="xs:hidden">{{ form.processing ? 'Creating...' : 'Create' }}</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fadeIn {
    animation: fadeIn 0.3s ease-out;
}

.overflow-y-auto::-webkit-scrollbar {
    width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

input[type=number] {
    -moz-appearance: textfield;
}

/* Extra small devices */
@media (max-width: 480px) {
    .xs\:hidden {
        display: none !important;
    }
}

@media (min-width: 481px) {
    .xs\:inline {
        display: inline !important;
    }
    .xs\:block {
        display: block !important;
    }
    .xs\:flex {
        display: flex !important;
    }
    .xs\:grid-cols-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>