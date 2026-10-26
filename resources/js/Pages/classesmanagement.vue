<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';

// =====================================================
// PROPS
// =====================================================

const props = defineProps({
    classes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
    teachers: { type: Array, default: () => [] },
    // ✅ NEW
    availableYears: { type: Array, default: () => [] },
    selectedYear: { type: String, default: '' },
});

// =====================================================
// ✅ YEAR STATE (always current year on page load)
// =====================================================

const selectedYear = ref(
    String(props.selectedYear || props.filters?.academic_year || new Date().getFullYear())
);
const yearInput = ref(String(selectedYear.value));

const yearOptions = computed(() => {
    const years = [...(props.availableYears || [])].map(String);
    const currentYear = String(new Date().getFullYear());
    if (!years.includes(currentYear)) years.push(currentYear);
    return years.sort((a, b) => String(b).localeCompare(String(a)));
});

// ✅ Year handlers
const applyYear = () => {
    const year = String(yearInput.value).trim();
    if (!/^\d{4}$/.test(year)) {
        yearInput.value = selectedYear.value;
        return;
    }
    const numYear = parseInt(year);
    if (numYear < 2000 || numYear > 2100) {
        yearInput.value = selectedYear.value;
        return;
    }
    if (year === selectedYear.value) return;
    selectedYear.value = year;
    fetchClasses(1);
};

const incrementYear = () => {
    yearInput.value = String(parseInt(yearInput.value || selectedYear.value) + 1);
    applyYear();
};

const decrementYear = () => {
    yearInput.value = String(parseInt(yearInput.value || selectedYear.value) - 1);
    applyYear();
};

// ✅ Sync from server
watch(() => props.selectedYear, (newYear) => {
    if (newYear && String(newYear) !== String(selectedYear.value)) {
        selectedYear.value = String(newYear);
        yearInput.value = String(newYear);
    }
});

// =====================================================
// STATE
// =====================================================

const searchQuery = ref(props.filters.search || '');
const filterStatus = ref(props.filters.status || '');
const sortField = ref(props.filters.sortField || 'created_at');
const sortDirection = ref(props.filters.sortDirection || 'desc');
const showCreateModal = ref(false);
const showEditModal = ref(false);
const selectedClass = ref(null);
const newSubject = ref('');
const showSubjectInput = ref(false);
const selectedSubjects = ref([]);

// Teacher search for edit modal
const editTeacherSearchQuery = ref('');
const editFilteredTeachers = ref([]);
const editShowTeacherSuggestions = ref(false);

// Available subjects for create modal
const availableSubjects = [
    { id: 1, name: 'Bangla', icon: '📖' },
    { id: 2, name: 'English', icon: '🔤' },
    { id: 3, name: 'Mathematics', icon: '📐' },
    { id: 4, name: 'Science', icon: '🔬' },
    { id: 5, name: 'Bangladesh & Global Studies', icon: '🌍' },
    { id: 6, name: 'Religion', icon: '🕌' },
    { id: 7, name: 'ICT', icon: '💻' },
];

// =====================================================
// TOAST
// =====================================================

const toast = ref({
    show: false,
    message: '',
    type: 'success',
    timeout: null
});

const showToast = (message, type = 'success') => {
    if (toast.value.timeout) clearTimeout(toast.value.timeout);
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
            bg: 'bg-emerald-50 dark:bg-emerald-900/30',
            border: 'border-emerald-400 dark:border-emerald-600',
            text: 'text-emerald-800 dark:text-emerald-300',
            icon: 'text-emerald-400 dark:text-emerald-300'
        },
        error: {
            bg: 'bg-rose-50 dark:bg-rose-900/30',
            border: 'border-rose-400 dark:border-rose-600',
            text: 'text-rose-800 dark:text-rose-300',
            icon: 'text-rose-400 dark:text-rose-300'
        },
        warning: {
            bg: 'bg-amber-50 dark:bg-amber-900/30',
            border: 'border-amber-400 dark:border-amber-600',
            text: 'text-amber-800 dark:text-amber-300',
            icon: 'text-amber-400 dark:text-amber-300'
        }
    };
    return styles[toast.value.type] || styles.success;
};

// =====================================================
// FORMS
// =====================================================

const createForm = useForm({
    className: '',
    section: '',
    academicYear: String(new Date().getFullYear()),
    classTeacher: '',
    status: 'active',
    subjects: [],
    maxStudents: null,
    description: '',
});

const editForm = useForm({
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
// COMPUTED
// =====================================================

const classList = computed(() => {
    return props.classes?.data || props.classes || [];
});

const pagination = computed(() => {
    return props.classes?.data ? {
        currentPage: props.classes.current_page,
        lastPage: props.classes.last_page,
        perPage: props.classes.per_page,
        total: props.classes.total,
        from: props.classes.from,
        to: props.classes.to,
        links: props.classes.links || []
    } : null;
});

const hasClasses = computed(() => classList.value.length > 0);

const totalClasses = computed(() => classList.value.length);
const activeClasses = computed(() => classList.value.filter(c => c.status === 'active').length);
const inactiveClasses = computed(() => classList.value.filter(c => c.status === 'inactive').length);

// Edit form subjects
const editSelectedSubjects = ref([]);

// Watch for edit teacher search
watch(editTeacherSearchQuery, (value) => {
    if (value && value.trim()) {
        const search = value.toLowerCase().trim();
        editFilteredTeachers.value = props.teachers.filter(teacher =>
            teacher.name.toLowerCase().includes(search) ||
            teacher.email.toLowerCase().includes(search)
        );
        editShowTeacherSuggestions.value = editFilteredTeachers.value.length > 0;
    } else {
        editShowTeacherSuggestions.value = false;
        editFilteredTeachers.value = [];
    }
});

// =====================================================
// METHODS
// =====================================================

const fetchClasses = (page = 1) => {
    const query = {
        page,
        academic_year: selectedYear.value,
        search: searchQuery.value || undefined,
        status: filterStatus.value || undefined,
        sortField: sortField.value,
        sortDirection: sortDirection.value
    };

    router.get('/classesmanagement', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: (page) => {
            if (page.props.flash?.success) showToast(page.props.flash.success, 'success');
            if (page.props.flash?.error) showToast(page.props.flash.error, 'error');
            if (page.props.flash?.warning) showToast(page.props.flash.warning, 'warning');
        }
    });
};

const resetFilters = () => {
    searchQuery.value = '';
    filterStatus.value = '';
    sortField.value = 'created_at';
    sortDirection.value = 'desc';
    // ✅ Year NOT reset — keep current selection
    fetchClasses(1);
};

const sortBy = (field) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
    fetchClasses(1);
};

const openCreateModal = () => {
    createForm.reset();
    createForm.academicYear = String(selectedYear.value); // ✅ prefill with selected year
    selectedSubjects.value = [];
    createForm.subjects = [];
    showCreateModal.value = true;
    document.body.style.overflow = 'hidden';
};

const openEditModal = (classItem) => {
    selectedClass.value = classItem;

    editForm.className = classItem.class_name || '';
    editForm.section = classItem.section || '';
    editForm.academicYear = classItem.academic_year || '';
    editForm.status = classItem.status || 'active';
    editForm.maxStudents = classItem.max_students ?? null;
    editForm.description = classItem.description || '';

    editForm.classTeacher = '';
    const teacher = props.teachers.find(t => t.name === classItem.class_teacher);
    if (teacher) {
        editForm.classTeacher = teacher.id;
        editTeacherSearchQuery.value = `${teacher.name} (${teacher.email})`;
    } else {
        editTeacherSearchQuery.value = classItem.class_teacher || '';
    }

    let subjects = classItem.subjects || [];
    if (typeof subjects === 'string') {
        try { subjects = JSON.parse(subjects); } catch { subjects = []; }
    }
    if (!Array.isArray(subjects)) subjects = [];

    subjects = subjects.map(s => String(s).trim()).filter(s => s !== '');
    editForm.subjects = [...subjects];

    editSelectedSubjects.value = subjects.map((name, index) => {
        const found = availableSubjects.find(s => s.name.toLowerCase() === name.toLowerCase());
        if (found) return { ...found };
        return {
            id: `custom-${index}-${Date.now()}`,
            name: name,
            icon: '📚',
            custom: true
        };
    });

    showEditModal.value = true;
    document.body.style.overflow = 'hidden';
};

const closeModals = () => {
    showCreateModal.value = false;
    showEditModal.value = false;
    selectedClass.value = null;
    createForm.reset();
    editForm.reset();
    selectedSubjects.value = [];
    editSelectedSubjects.value = [];
    newSubject.value = '';
    showSubjectInput.value = false;
    editTeacherSearchQuery.value = '';
    editShowTeacherSuggestions.value = false;
    editFilteredTeachers.value = [];
    document.body.style.overflow = '';
};

const toggleSubject = (subject) => {
    const index = selectedSubjects.value.findIndex(s => s.id === subject.id);
    if (index > -1) selectedSubjects.value.splice(index, 1);
    else selectedSubjects.value.push(subject);
    createForm.subjects = selectedSubjects.value.map(s => s.name);
};

const isSelected = (subject) => selectedSubjects.value.some(s => s.id === subject.id);

const addCustomSubject = () => {
    const subjectName = newSubject.value.trim();
    if (!subjectName) return;
    const exists = selectedSubjects.value.some(s => s.name.toLowerCase() === subjectName.toLowerCase());
    if (exists) {
        showToast('This subject is already selected.', 'warning');
        return;
    }
    selectedSubjects.value.push({ id: Date.now(), name: subjectName, icon: '📚', custom: true });
    createForm.subjects = selectedSubjects.value.map(s => s.name);
    newSubject.value = '';
    showSubjectInput.value = false;
};

const removeSubject = (subjectId) => {
    selectedSubjects.value = selectedSubjects.value.filter(s => s.id !== subjectId);
    createForm.subjects = selectedSubjects.value.map(s => s.name);
};

const editToggleSubject = (subject) => {
    const index = editSelectedSubjects.value.findIndex(s => s.name.toLowerCase() === subject.name.toLowerCase());
    if (index > -1) editSelectedSubjects.value.splice(index, 1);
    else editSelectedSubjects.value.push({ ...subject });
    editForm.subjects = editSelectedSubjects.value.map(s => s.name);
};

const editIsSelected = (subject) => editSelectedSubjects.value.some(s => s.name.toLowerCase() === subject.name.toLowerCase());

const editAddCustomSubject = () => {
    const subjectName = newSubject.value.trim();
    if (!subjectName) return;
    const exists = editSelectedSubjects.value.some(s => s.name.toLowerCase() === subjectName.toLowerCase());
    if (exists) {
        showToast('This subject is already selected.', 'warning');
        return;
    }
    editSelectedSubjects.value.push({ id: `custom-${Date.now()}`, name: subjectName, icon: '📚', custom: true });
    editForm.subjects = editSelectedSubjects.value.map(s => s.name);
    newSubject.value = '';
    showSubjectInput.value = false;
};

const editRemoveSubject = (subjectId) => {
    editSelectedSubjects.value = editSelectedSubjects.value.filter(s => s.id !== subjectId);
    editForm.subjects = editSelectedSubjects.value.map(s => s.name);
};

const editSelectTeacher = (teacher) => {
    editForm.classTeacher = teacher.id;
    editTeacherSearchQuery.value = `${teacher.name} (${teacher.email})`;
    editShowTeacherSuggestions.value = false;
    editFilteredTeachers.value = [];
};

const editClearTeacher = () => {
    editForm.classTeacher = '';
    editTeacherSearchQuery.value = '';
    editShowTeacherSuggestions.value = false;
    editFilteredTeachers.value = [];
};

const editCloseTeacherSuggestions = () => {
    setTimeout(() => { editShowTeacherSuggestions.value = false; }, 200);
};

const createClass = () => {
    createForm.post('/classesmanagement', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (response) => {
            closeModals();
            if (response.props.flash?.success) showToast(response.props.flash.success, 'success');
            else showToast('Class created successfully! 🎉', 'success');
            fetchClasses(pagination.value?.currentPage || 1);
        },
        onError: () => showToast('Please fix the validation errors.', 'error')
    });
};

const updateClass = () => {
    if (!selectedClass.value) return;
    const payload = {
        ...editForm.data(),
        classTeacher: parseInt(editForm.classTeacher) || 0
    };
    editForm.put(`/classesmanagement/${selectedClass.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (response) => {
            closeModals();
            if (response.props.flash?.success) showToast(response.props.flash.success, 'success');
            else showToast('Class updated successfully! 🎉', 'success');
            fetchClasses(pagination.value?.currentPage || 1);
        },
        onError: () => showToast('Please fix the validation errors.', 'error')
    });
};

const deleteClass = (classItem) => {
    if (!confirm(`Are you sure you want to delete "${classItem.class_name}"? This action cannot be undone.`)) return;
    router.delete(`/classesmanagement/${classItem.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (response) => {
            if (response.props.flash?.success) showToast(response.props.flash.success, 'success');
            else showToast('Class deleted successfully! 🎉', 'success');
            fetchClasses(pagination.value?.currentPage || 1);
        },
        onError: () => showToast('Failed to delete class. Please try again.', 'error')
    });
};

const getStatusBadge = (status) => {
    const colors = {
        active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
        inactive: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'
    };
    return colors[status] || 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300';
};

// =====================================================
// WATCHERS
// =====================================================

let searchTimeout = null;
watch(searchQuery, (newVal, oldVal) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        if (newVal !== oldVal) fetchClasses(1);
    }, 500);
});

watch(filterStatus, (newVal, oldVal) => {
    if (newVal !== oldVal) fetchClasses(1);
});

// =====================================================
// MOUNT
// =====================================================

onMounted(() => {
    if (props.flash?.success) setTimeout(() => showToast(props.flash.success, 'success'), 100);
    if (props.flash?.error) setTimeout(() => showToast(props.flash.error, 'error'), 100);
    if (props.flash?.warning) setTimeout(() => showToast(props.flash.warning, 'warning'), 100);
});
</script>

<template>
    <Head title="Classes Management" />

    <AuthenticatedLayout>
        <div class="min-h-screen py-4 sm:py-6 px-3 sm:px-4 lg:px-6 relative bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 dark:from-slate-900 dark:via-slate-800 dark:to-indigo-950/20 transition-colors duration-300">

            <!-- Toast -->
            <transition
                enter-active-class="transform transition-all duration-500 ease-in-out"
                enter-from-class="translate-x-full opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transform transition-all duration-500 ease-in-out"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-full opacity-0"
            >
                <div v-if="toast.show" class="fixed top-4 right-4 left-4 sm:left-auto z-[100] max-w-xs sm:max-w-md w-full mx-auto sm:mx-0">
                    <div :class="['rounded-2xl shadow-2xl border p-3 sm:p-4 backdrop-blur-xl', getToastStyles().bg, getToastStyles().border]">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                <svg v-if="toast.type === 'success'" class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <svg v-else-if="toast.type === 'error'" class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <svg v-else class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p :class="['text-sm font-medium break-words', getToastStyles().text]">{{ toast.message }}</p>
                            </div>
                            <button @click="hideToast" class="flex-shrink-0 -mt-1 -mr-1 p-1 hover:bg-black/10 dark:hover:bg-white/10 rounded-xl transition-colors">
                                <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- Page Header -->
            <div class="mb-5 sm:mb-8">
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="p-2.5 sm:p-3 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg shadow-indigo-500/20 ring-1 ring-white/20 flex-shrink-0">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2M9 7a4 4 0 100-8 4 4 0 000 8z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 dark:from-white dark:to-slate-300 bg-clip-text text-transparent truncate">
                            Classes Management
                        </h1>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                            Manage all your classes from one place
                        </p>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 lg:gap-5 mb-5 sm:mb-6">
                <div class="group bg-white dark:bg-slate-800/60 rounded-2xl shadow-sm border border-slate-200/60 dark:border-slate-700/50 p-4 sm:p-5 transition-all duration-300 hover:shadow-xl hover:scale-[1.02]">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400 truncate">Total Classes</p>
                            <p class="text-2xl sm:text-3xl lg:text-4xl font-bold text-slate-800 dark:text-white mt-1">{{ totalClasses }}</p>
                        </div>
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-2xl shadow-lg shadow-indigo-500/20 flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2M9 7a4 4 0 100-8 4 4 0 000 8z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="group bg-white dark:bg-slate-800/60 rounded-2xl shadow-sm border border-slate-200/60 dark:border-slate-700/50 p-4 sm:p-5 transition-all duration-300 hover:shadow-xl hover:scale-[1.02]">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400 truncate">Active Classes</p>
                            <p class="text-2xl sm:text-3xl lg:text-4xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ activeClasses }}</p>
                        </div>
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-500 to-teal-500 rounded-2xl shadow-lg shadow-emerald-500/20 flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="group bg-white dark:bg-slate-800/60 rounded-2xl shadow-sm border border-slate-200/60 dark:border-slate-700/50 p-4 sm:p-5 transition-all duration-300 hover:shadow-xl hover:scale-[1.02]">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400 truncate">Inactive Classes</p>
                            <p class="text-2xl sm:text-3xl lg:text-4xl font-bold text-rose-600 dark:text-rose-400 mt-1">{{ inactiveClasses }}</p>
                        </div>
                        <div class="p-2.5 sm:p-3 bg-gradient-to-br from-rose-500 to-pink-500 rounded-2xl shadow-lg shadow-rose-500/20 flex-shrink-0">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ✅ YEAR FILTER (Type + ± Buttons) -->
            <div class="mb-5 sm:mb-6 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-950/30 dark:to-purple-950/30 rounded-2xl border-2 border-indigo-200/60 dark:border-indigo-800/50 shadow-md p-4 sm:p-5 transition-colors duration-300">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 sm:p-2.5 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 shadow-lg shadow-indigo-500/25 flex-shrink-0">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-indigo-900 dark:text-indigo-200">Academic Year</h3>
                            <p class="text-xs text-indigo-600 dark:text-indigo-400">Type year or use buttons</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5 sm:gap-2 w-full sm:w-auto">
                        <button
                            type="button"
                            @click="decrementYear"
                            class="p-2 sm:p-2.5 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-500 transition-all active:scale-95 flex-shrink-0"
                            title="Previous year"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                            class="w-24 sm:w-32 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-slate-800 px-3 sm:px-4 py-2 sm:py-2.5 text-base font-bold text-indigo-900 dark:text-indigo-200 text-center tracking-wider outline-none transition-all focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/20"
                        />

                        <button
                            type="button"
                            @click="incrementYear"
                            class="p-2 sm:p-2.5 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-500 transition-all active:scale-95 flex-shrink-0"
                            title="Next year"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>

                        <span v-if="String(selectedYear) === String(new Date().getFullYear())" class="ml-1 sm:ml-2 px-2 sm:px-3 py-1 sm:py-1.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700 flex-shrink-0">
                            NOW
                        </span>
                    </div>
                </div>

                <!-- Quick Year Suggestions -->
                <div v-if="yearOptions.length > 0" class="mt-3 sm:mt-4 flex flex-wrap items-center gap-2 pt-3 border-t border-indigo-200/60 dark:border-indigo-800/50">
                    <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Quick select:</span>
                    <button
                        v-for="year in yearOptions.slice(0, 6)"
                        :key="year"
                        type="button"
                        @click="yearInput = String(year); applyYear()"
                        :class="[
                            'px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-xs font-bold transition-all active:scale-95',
                            String(year) === String(selectedYear)
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-500/30'
                                : 'bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:border-indigo-400'
                        ]"
                    >
                        {{ year }}
                    </button>
                </div>
            </div>

            <!-- Header & Create Button -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4 mb-5 sm:mb-6">
                <div class="flex items-center gap-3">
                    <div class="h-8 w-1 bg-gradient-to-b from-indigo-500 to-purple-500 rounded-full"></div>
                    <div>
                        <h2 class="text-base sm:text-lg font-semibold text-slate-700 dark:text-slate-300">Class List</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500">Manage all your classes</p>
                    </div>
                </div>
                <button
                    @click="openCreateModal"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white text-sm font-medium rounded-xl transition-all duration-300 shadow-md hover:shadow-lg hover:scale-[1.02] active:scale-[0.98] w-full sm:w-auto"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add New Class
                </button>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-slate-800/60 rounded-2xl shadow-sm border border-slate-200/60 dark:border-slate-700/50 p-3 sm:p-4 mb-5 sm:mb-6 backdrop-blur-sm transition-colors duration-300">
                <div class="flex flex-col sm:flex-row gap-3 sm:gap-4">
                    <div class="flex-1 relative">
                        <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by class name..."
                            class="w-full pl-11 pr-4 py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm transition-all duration-200"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2 sm:gap-3">
                        <select
                            v-model="filterStatus"
                            class="flex-1 sm:flex-none px-3 py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white min-w-0 sm:min-w-[130px] text-sm transition-all duration-200"
                        >
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <button
                            @click="resetFilters"
                            class="px-4 py-2.5 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white border border-slate-200 dark:border-slate-600 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-all duration-200 flex-shrink-0"
                        >
                            Reset
                        </button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800/60 rounded-2xl shadow-sm border border-slate-200/60 dark:border-slate-700/50 overflow-hidden backdrop-blur-sm transition-colors duration-300">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px]">
                        <thead>
                            <tr class="border-b border-slate-200/60 dark:border-slate-700/50 bg-gradient-to-r from-slate-50/80 to-indigo-50/50 dark:from-slate-800/80 dark:to-indigo-950/30">
                                <th class="px-3 sm:px-4 py-3 sm:py-3.5 text-left">
                                    <button @click="sortBy('class_name')" class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
                                        Class Name
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                                        </svg>
                                    </button>
                                </th>
                                <th class="px-3 sm:px-4 py-3 sm:py-3.5 text-left">
                                    <button @click="sortBy('section')" class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
                                        Section
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                                        </svg>
                                    </button>
                                </th>
                                <th class="px-3 sm:px-4 py-3 sm:py-3.5 text-left">
                                    <button @click="sortBy('academic_year')" class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
                                        Academic Year
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                                        </svg>
                                    </button>
                                </th>
                                <th class="px-3 sm:px-4 py-3 sm:py-3.5 text-left">
                                    <button @click="sortBy('class_teacher')" class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
                                        Class Teacher
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                                        </svg>
                                    </button>
                                </th>
                                <th class="px-3 sm:px-4 py-3 sm:py-3.5 text-left">
                                    <button @click="sortBy('status')" class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
                                        Status
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                                        </svg>
                                    </button>
                                </th>
                                <th class="px-3 sm:px-4 py-3 sm:py-3.5 text-left">
                                    <button @click="sortBy('created_at')" class="flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
                                        Created
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                                        </svg>
                                    </button>
                                </th>
                                <th class="px-3 sm:px-4 py-3 sm:py-3.5 text-right">
                                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!hasClasses" class="border-b border-slate-200/60 dark:border-slate-700/50">
                                <td colspan="7" class="px-4 py-12 sm:py-16 text-center text-slate-500 dark:text-slate-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="text-5xl sm:text-6xl">📚</div>
                                        <p class="text-sm font-medium">No classes found</p>
                                        <p class="text-xs">No classes for year <strong>{{ selectedYear }}</strong></p>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-for="classItem in classList"
                                :key="classItem.id"
                                class="border-b border-slate-200/60 dark:border-slate-700/50 hover:bg-slate-50/80 dark:hover:bg-slate-700/30 transition-all duration-200 group"
                            >
                                <td class="px-3 sm:px-4 py-3 sm:py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-100 to-purple-100 dark:from-indigo-900/50 dark:to-purple-900/50 flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform duration-200">
                                            <span class="text-sm font-bold text-indigo-600 dark:text-indigo-300">
                                                {{ classItem.class_name.charAt(0).toUpperCase() }}
                                            </span>
                                        </div>
                                        <span class="text-sm font-medium text-slate-800 dark:text-white truncate">
                                            {{ classItem.class_name }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-3.5">
                                    <span class="text-sm text-slate-600 dark:text-slate-300">{{ classItem.section || '-' }}</span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-3.5">
                                    <span class="text-sm text-slate-600 dark:text-slate-300">{{ classItem.academic_year }}</span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-3.5">
                                    <span class="text-sm text-slate-600 dark:text-slate-300 truncate">{{ classItem.class_teacher }}</span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-3.5">
                                    <span :class="['inline-flex items-center px-2 sm:px-2.5 py-1 rounded-full text-xs font-medium whitespace-nowrap', getStatusBadge(classItem.status)]">
                                        <span class="w-1.5 h-1.5 rounded-full mr-1.5" :class="classItem.status === 'active' ? 'bg-emerald-400' : 'bg-rose-400'"></span>
                                        {{ classItem.status }}
                                    </span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-3.5">
                                    <span class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                                        {{ new Date(classItem.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) }}
                                    </span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-3.5">
                                    <div class="flex items-center justify-end gap-1 sm:gap-1.5">
                                        <button @click="openEditModal(classItem)" class="p-1.5 sm:p-2 text-blue-500 hover:text-blue-700 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20 rounded-xl transition-all duration-200 hover:scale-110" title="Edit class">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <button @click="deleteClass(classItem)" class="p-1.5 sm:p-2 text-rose-500 hover:text-rose-700 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-900/20 rounded-xl transition-all duration-200 hover:scale-110" title="Delete class">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="pagination && pagination.lastPage > 1" class="flex flex-col sm:flex-row items-center justify-between px-3 sm:px-4 py-3 sm:py-4 gap-3 border-t border-slate-200/60 dark:border-slate-700/50 bg-gradient-to-r from-slate-50/80 to-indigo-50/50 dark:from-slate-800/80 dark:to-indigo-950/30">
                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 text-center sm:text-left">
                        Showing <span class="font-medium text-slate-700 dark:text-slate-300">{{ pagination.from }}</span>
                        to <span class="font-medium text-slate-700 dark:text-slate-300">{{ pagination.to }}</span>
                        of <span class="font-medium text-slate-700 dark:text-slate-300">{{ pagination.total }}</span> results
                    </div>
                    <div class="flex gap-1 flex-wrap justify-center">
                        <button
                            @click="fetchClasses(pagination.currentPage - 1)"
                            :disabled="pagination.currentPage === 1"
                            class="px-2.5 sm:px-3.5 py-1.5 sm:py-2 text-sm border border-slate-200 dark:border-slate-600 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 text-slate-600 dark:text-slate-300"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <button
                            v-for="page in pagination.links?.slice(1, -1)"
                            :key="page.label"
                            @click="fetchClasses(page.label)"
                            :class="[
                                'px-2.5 sm:px-3.5 py-1.5 sm:py-2 text-sm rounded-xl transition-all duration-200 font-medium',
                                page.active
                                    ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-md'
                                    : 'border border-slate-200 dark:border-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300'
                            ]"
                        >
                            {{ page.label }}
                        </button>
                        <button
                            @click="fetchClasses(pagination.currentPage + 1)"
                            :disabled="pagination.currentPage === pagination.lastPage"
                            class="px-2.5 sm:px-3.5 py-1.5 sm:py-2 text-sm border border-slate-200 dark:border-slate-600 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 text-slate-600 dark:text-slate-300"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="closeModals">
            <div class="flex min-h-screen items-start sm:items-center justify-center p-2 sm:p-4">
                <div class="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity duration-300" :class="{ 'opacity-100': showCreateModal, 'opacity-0': !showCreateModal }" @click="closeModals"></div>
                <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-2xl w-full p-4 sm:p-6 my-4 sm:my-0 transition-all duration-300 ease-out border border-white/20 dark:border-slate-700/50" :class="{ 'scale-100 opacity-100 translate-y-0': showCreateModal, 'scale-95 opacity-0 translate-y-4': !showCreateModal }">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <h2 class="text-lg sm:text-xl font-bold text-slate-800 dark:text-white">Create New Class</h2>
                        <button @click="closeModals" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-xl transition-colors">
                            <svg class="w-5 h-5 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form @submit.prevent="createClass" class="space-y-3 sm:space-y-4 max-h-[75vh] sm:max-h-[80vh] overflow-y-auto pr-1 sm:pr-2">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Class Name *</label>
                                <input v-model="createForm.className" type="text" placeholder="e.g. Class 1" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Section (Optional)</label>
                                <input v-model="createForm.section" type="text" placeholder="e.g. A" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Academic Year *</label>
                                <input v-model="createForm.academicYear" type="text" placeholder="e.g. 2026" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Class Teacher *</label>
                                <input v-model="createForm.classTeacher" type="text" placeholder="Enter teacher ID or name" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                <select v-model="createForm.status" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white transition-all duration-200">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Max Students</label>
                                <input v-model.number="createForm.maxStudents" type="number" min="1" max="200" placeholder="e.g. 40" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description (Optional)</label>
                                <textarea v-model="createForm.description" rows="2" placeholder="Add a short description..." class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 resize-none transition-all duration-200"></textarea>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Subjects *</label>
                                <div class="space-y-2">
                                    <div v-if="selectedSubjects.length > 0" class="flex flex-wrap gap-1.5">
                                        <span v-for="subject in selectedSubjects" :key="subject.id" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1 sm:py-1.5 bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded-full text-xs font-medium">
                                            {{ subject.icon }} {{ subject.name }}
                                            <button @click="removeSubject(subject.id)" type="button" class="hover:bg-indigo-200 dark:hover:bg-indigo-800 rounded-full p-0.5 transition-colors">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </span>
                                    </div>
                                    <div v-if="showSubjectInput" class="flex flex-col sm:flex-row gap-2">
                                        <input v-model="newSubject" type="text" placeholder="Type subject name..." class="flex-1 px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" @keyup.enter="addCustomSubject" />
                                        <div class="flex gap-2">
                                            <button type="button" @click="addCustomSubject" class="flex-1 sm:flex-none px-4 py-2 sm:py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-xl transition-colors duration-200">Add</button>
                                            <button type="button" @click="showSubjectInput = false; newSubject = ''" class="flex-1 sm:flex-none px-4 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors duration-200">Cancel</button>
                                        </div>
                                    </div>
                                    <button v-else type="button" @click="showSubjectInput = true" class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition-colors">+ Add Custom Subject</button>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
                                        <label v-for="subject in availableSubjects" :key="subject.id" class="flex items-center gap-2.5 text-sm cursor-pointer hover:text-indigo-600 dark:hover:text-indigo-400 text-slate-700 dark:text-slate-300 transition-colors">
                                            <input type="checkbox" :checked="isSelected(subject)" @change="toggleSubject(subject)" class="rounded border-slate-300 dark:border-slate-600 text-indigo-600 focus:ring-indigo-500 w-4 h-4" />
                                            <span>{{ subject.icon }} {{ subject.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 sm:gap-3 pt-4 border-t border-slate-200/60 dark:border-slate-700/50">
                            <button type="button" @click="closeModals" class="w-full sm:w-auto px-5 py-2.5 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white border border-slate-200 dark:border-slate-600 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-all duration-200">Cancel</button>
                            <button type="submit" :disabled="createForm.processing" class="w-full sm:w-auto px-6 py-2.5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white text-sm font-medium rounded-xl transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed shadow-md hover:shadow-lg">
                                <span v-if="createForm.processing">Creating...</span>
                                <span v-else>Create Class</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="closeModals">
            <div class="flex min-h-screen items-start sm:items-center justify-center p-2 sm:p-4">
                <div class="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity duration-300" :class="{ 'opacity-100': showEditModal, 'opacity-0': !showEditModal }" @click="closeModals"></div>
                <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-2xl w-full p-4 sm:p-6 my-4 sm:my-0 transition-all duration-300 ease-out border border-white/20 dark:border-slate-700/50" :class="{ 'scale-100 opacity-100 translate-y-0': showEditModal, 'scale-95 opacity-0 translate-y-4': !showEditModal }">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <h2 class="text-lg sm:text-xl font-bold text-slate-800 dark:text-white">Edit Class</h2>
                        <button @click="closeModals" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-xl transition-colors">
                            <svg class="w-5 h-5 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form @submit.prevent="updateClass" class="space-y-3 sm:space-y-4 max-h-[75vh] sm:max-h-[80vh] overflow-y-auto pr-1 sm:pr-2">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Class Name *</label>
                                <input v-model="editForm.className" type="text" placeholder="e.g. Class 1" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Section (Optional)</label>
                                <input v-model="editForm.section" type="text" placeholder="e.g. A" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Academic Year *</label>
                                <input v-model="editForm.academicYear" type="text" placeholder="e.g. 2026" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Class Teacher *</label>
                                <div class="relative">
                                    <input
                                        v-model="editTeacherSearchQuery"
                                        type="text"
                                        placeholder="Type teacher name or email..."
                                        class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200 pr-10"
                                        @focus="editShowTeacherSuggestions = true"
                                        @blur="editCloseTeacherSuggestions"
                                    />
                                    <button v-if="editTeacherSearchQuery" type="button" @click="editClearTeacher" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                    <div v-if="editShowTeacherSuggestions && editFilteredTeachers.length > 0" class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl max-h-48 overflow-y-auto">
                                        <button v-for="teacher in editFilteredTeachers" :key="teacher.id" type="button" @mousedown.prevent="editSelectTeacher(teacher)" class="w-full text-left px-3 sm:px-4 py-2.5 sm:py-3 text-sm hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors flex items-center gap-3 border-b border-slate-100 dark:border-slate-700 last:border-b-0">
                                            <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-gradient-to-br from-indigo-100 to-purple-100 dark:from-indigo-900/50 dark:to-purple-900/50 flex items-center justify-center text-xs font-bold text-indigo-600 dark:text-indigo-300 flex-shrink-0">
                                                {{ teacher.name.charAt(0).toUpperCase() }}
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-medium text-slate-800 dark:text-white truncate">{{ teacher.name }}</div>
                                                <div class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ teacher.email }}</div>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                <select v-model="editForm.status" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white transition-all duration-200">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Max Students</label>
                                <input v-model.number="editForm.maxStudents" type="number" min="1" max="200" placeholder="e.g. 40" class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description (Optional)</label>
                                <textarea v-model="editForm.description" rows="2" placeholder="Add a short description..." class="w-full px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 resize-none transition-all duration-200"></textarea>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Subjects *</label>
                                <div class="space-y-2">
                                    <div v-if="editSelectedSubjects.length > 0" class="flex flex-wrap gap-1.5">
                                        <span v-for="subject in editSelectedSubjects" :key="subject.id" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1 sm:py-1.5 bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded-full text-xs font-medium">
                                            {{ subject.icon }} {{ subject.name }}
                                            <button @click="editRemoveSubject(subject.id)" type="button" class="hover:bg-indigo-200 dark:hover:bg-indigo-800 rounded-full p-0.5 transition-colors">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </span>
                                    </div>
                                    <div v-if="showSubjectInput" class="flex flex-col sm:flex-row gap-2">
                                        <input v-model="newSubject" type="text" placeholder="Type subject name..." class="flex-1 px-3 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-slate-700 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 transition-all duration-200" @keyup.enter="editAddCustomSubject" />
                                        <div class="flex gap-2">
                                            <button type="button" @click="editAddCustomSubject" class="flex-1 sm:flex-none px-4 py-2 sm:py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-xl transition-colors duration-200">Add</button>
                                            <button type="button" @click="showSubjectInput = false; newSubject = ''" class="flex-1 sm:flex-none px-4 py-2 sm:py-2.5 border border-slate-200 dark:border-slate-600 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors duration-200">Cancel</button>
                                        </div>
                                    </div>
                                    <button v-else type="button" @click="showSubjectInput = true" class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition-colors">+ Add Custom Subject</button>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
                                        <label v-for="subject in availableSubjects" :key="subject.id" class="flex items-center gap-2.5 text-sm cursor-pointer hover:text-indigo-600 dark:hover:text-indigo-400 text-slate-700 dark:text-slate-300 transition-colors">
                                            <input type="checkbox" :checked="editIsSelected(subject)" @change="editToggleSubject(subject)" class="rounded border-slate-300 dark:border-slate-600 text-indigo-600 focus:ring-indigo-500 w-4 h-4" />
                                            <span>{{ subject.icon }} {{ subject.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 sm:gap-3 pt-4 border-t border-slate-200/60 dark:border-slate-700/50">
                            <button type="button" @click="closeModals" class="w-full sm:w-auto px-5 py-2.5 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white border border-slate-200 dark:border-slate-600 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-all duration-200">Cancel</button>
                            <button type="submit" :disabled="editForm.processing" class="w-full sm:w-auto px-6 py-2.5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white text-sm font-medium rounded-xl transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed shadow-md hover:shadow-lg">
                                <span v-if="editForm.processing">Updating...</span>
                                <span v-else>Update Class</span>
                            </button>
                        </div>
                    </form>
                </div>
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
</style>