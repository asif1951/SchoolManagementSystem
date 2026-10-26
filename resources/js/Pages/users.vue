<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { 
    PlusIcon, 
    PencilIcon, 
    TrashIcon, 
    MagnifyingGlassIcon,
    XMarkIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ChevronUpDownIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    UsersIcon,
    ShieldCheckIcon,
    AcademicCapIcon
} from '@heroicons/vue/24/outline';

// Props from controller
const props = defineProps({
    users: {
        type: Object,
        required: true
    },
    filters: {
        type: Object,
        default: () => ({})
    },
    stats: {
        type: Object,
        default: () => ({ total: 0, admins: 0, teachers: 0 })
    },
    flash: {
        type: [Object, Array],
        default: () => ({})
    }
});

// State
const showCreateModal = ref(false);
const showEditModal = ref(false);
const selectedUser = ref(null);
const searchQuery = ref(props.filters.search || '');
const filterStatus = ref(props.filters.status || '');
const filterRole = ref(props.filters.role || '');
const sortField = ref(props.filters.sortField || 'created_at');
const sortDirection = ref(props.filters.sortDirection || 'desc');

// Form for creating user
const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: '',
    status: 'active'
});

// Form for editing user
const editForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: '',
    status: ''
});

// Validation errors
const errors = ref({});

// ============================================================
// STAT CARDS
// ============================================================
const statCards = computed(() => [
    {
        key: 'total',
        label: 'Total Users',
        value: props.stats?.total ?? 0,
        icon: UsersIcon,
        iconBox: 'bg-gradient-to-br from-indigo-500 to-violet-600 shadow-indigo-500/30',
        accent: 'from-indigo-500 to-violet-600',
        tint: 'from-indigo-50/70 dark:from-indigo-500/5',
    },
    {
        key: 'admins',
        label: 'Admins',
        value: props.stats?.admins ?? 0,
        icon: ShieldCheckIcon,
        iconBox: 'bg-gradient-to-br from-purple-500 to-fuchsia-600 shadow-purple-500/30',
        accent: 'from-purple-500 to-fuchsia-600',
        tint: 'from-purple-50/70 dark:from-purple-500/5',
    },
    {
        key: 'teachers',
        label: 'Teachers',
        value: props.stats?.teachers ?? 0,
        icon: AcademicCapIcon,
        iconBox: 'bg-gradient-to-br from-blue-500 to-sky-600 shadow-blue-500/30',
        accent: 'from-blue-500 to-sky-600',
        tint: 'from-blue-50/70 dark:from-blue-500/5',
    },
]);

// ============================================================
// TOAST
// ============================================================
const toast = ref({ show: false, type: 'success', message: '' });
let toastTimer = null;

const hideToast = () => {
    toast.value.show = false;
    if (toastTimer) {
        clearTimeout(toastTimer);
        toastTimer = null;
    }
};

const showToast = (type, message) => {
    if (!message) return;
    if (toastTimer) clearTimeout(toastTimer);
    toast.value = { show: true, type, message };
    toastTimer = setTimeout(() => {
        toast.value.show = false;
    }, 4000);
};

// Show toast from server flash messages (create / update / delete)
watch(
    () => props.flash,
    (flash) => {
        if (!flash) return;
        if (flash.success) showToast('success', flash.success);
        else if (flash.error) showToast('error', flash.error);
        else if (flash.warning) showToast('error', flash.warning);
    },
    { immediate: true }
);

onBeforeUnmount(() => {
    if (toastTimer) clearTimeout(toastTimer);
});

// Computed
const hasUsers = computed(() => {
    return props.users?.data?.length > 0 || props.users?.length > 0;
});

const userList = computed(() => {
    return props.users?.data || props.users || [];
});

// ============================================================
// PAGINATION (backend theke)
// Laravel paginator-er links array: [Previous, 1, 2, ..., Next]
// ============================================================
const pagination = computed(() => {
    if (!props.users?.data) return null;

    const links = props.users.links || [];

    return {
        currentPage: props.users.current_page,
        lastPage: props.users.last_page,
        perPage: props.users.per_page,
        total: props.users.total,
        from: props.users.from,
        to: props.users.to,
        prevUrl: props.users.prev_page_url ?? null,
        nextUrl: props.users.next_page_url ?? null,
        // prothom (Previous) ar shesh (Next) baad diye shudhu page number / "..."
        pageLinks: links.slice(1, -1),
    };
});

// Backend-er dewa url-e jao (filter/search/sort query already url-e thake)
const goToUrl = (url) => {
    if (!url) return;
    router.get(url, {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// Methods
const fetchUsers = (page = 1) => {
    const query = {
        page,
        search: searchQuery.value,
        status: filterStatus.value,
        role: filterRole.value,
        sortField: sortField.value,
        sortDirection: sortDirection.value
    };

    // Remove empty values
    Object.keys(query).forEach(key => {
        if (!query[key]) delete query[key];
    });

    router.get('/users', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const resetFilters = () => {
    searchQuery.value = '';
    filterStatus.value = '';
    filterRole.value = '';
    sortField.value = 'created_at';
    sortDirection.value = 'desc';
    fetchUsers(1);
};

const sortBy = (field) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
    fetchUsers(1);
};

const openCreateModal = () => {
    createForm.reset();
    errors.value = {};
    showCreateModal.value = true;
};

const openEditModal = (user) => {
    selectedUser.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.role = user.role;
    editForm.status = user.status;
    editForm.password = '';
    editForm.password_confirmation = '';
    errors.value = {};
    showEditModal.value = true;
};

const closeModals = () => {
    showCreateModal.value = false;
    showEditModal.value = false;
    selectedUser.value = null;
    createForm.reset();
    editForm.reset();
    errors.value = {};
};

const createUser = () => {
    createForm.post('/users', {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
            fetchUsers(pagination.value?.currentPage || 1);
        },
        onError: (err) => {
            errors.value = err;
            showToast('error', 'Failed to create user. Please check the form.');
        }
    });
};

const updateUser = () => {
    if (!selectedUser.value) return;
    
    editForm.put(`/users/${selectedUser.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
            fetchUsers(pagination.value?.currentPage || 1);
        },
        onError: (err) => {
            errors.value = err;
            showToast('error', 'Failed to update user. Please check the form.');
        }
    });
};

const deleteUser = (user) => {
    if (!confirm(`Are you sure you want to delete "${user.name}"? This action cannot be undone.`)) {
        return;
    }

    router.delete(`/users/${user.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            fetchUsers(pagination.value?.currentPage || 1);
        },
        onError: () => {
            showToast('error', 'Failed to delete user.');
        }
    });
};

const getStatusBadge = (status) => {
    const colors = {
        active: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
        inactive: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
        suspended: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300'
    };
    return colors[status] || 'bg-gray-100 text-gray-800 dark:bg-slate-700 dark:text-slate-300';
};

const getRoleBadge = (role) => {
    const colors = {
        admin: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
        teacher: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
        user: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
    };
    return colors[role] || 'bg-gray-100 text-gray-800 dark:bg-slate-700 dark:text-slate-300';
};

// Watch for filter changes with debounce
let searchTimeout = null;
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchUsers(1);
    }, 500);
});

watch([filterStatus, filterRole], () => {
    fetchUsers(1);
});
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <!-- ==================== TOAST ==================== -->
        <Transition name="toast">
            <div
                v-if="toast.show"
                class="fixed top-4 right-4 z-[60] w-[calc(100%-2rem)] sm:w-auto sm:min-w-[320px] sm:max-w-sm"
                role="status"
                aria-live="polite"
            >
                <div
                    :class="[
                        'relative flex items-start gap-3 overflow-hidden rounded-xl border bg-white dark:bg-slate-900 px-4 py-3.5 shadow-2xl',
                        toast.type === 'success'
                            ? 'border-emerald-200 dark:border-emerald-800/60 shadow-emerald-500/10'
                            : 'border-red-200 dark:border-red-800/60 shadow-red-500/10'
                    ]"
                >
                    <!-- Accent bar -->
                    <span
                        :class="[
                            'absolute inset-y-0 left-0 w-1',
                            toast.type === 'success' ? 'bg-gradient-to-b from-emerald-400 to-emerald-600' : 'bg-gradient-to-b from-red-400 to-red-600'
                        ]"
                    ></span>

                    <span
                        :class="[
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                            toast.type === 'success'
                                ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400'
                                : 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400'
                        ]"
                    >
                        <CheckCircleIcon v-if="toast.type === 'success'" class="w-5 h-5" />
                        <ExclamationCircleIcon v-else class="w-5 h-5" />
                    </span>

                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-sm font-semibold text-slate-800 dark:text-white">
                            {{ toast.type === 'success' ? 'Success' : 'Error' }}
                        </p>
                        <p class="mt-0.5 text-xs sm:text-[13px] text-slate-600 dark:text-slate-300 break-words">
                            {{ toast.message }}
                        </p>
                    </div>

                    <button
                        type="button"
                        @click="hideToast"
                        class="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-200 transition-colors"
                        title="Close"
                    >
                        <XMarkIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </Transition>

        <div class="relative min-h-screen overflow-hidden py-6 sm:py-8 lg:py-12 bg-gradient-to-br from-slate-50 via-indigo-50/40 to-violet-50/50 dark:from-slate-950 dark:via-slate-950 dark:to-indigo-950/30 transition-colors duration-500">

            <!-- Decorative background blobs -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="blob absolute -top-32 -left-24 h-96 w-96 rounded-full bg-indigo-400/20 dark:bg-indigo-500/10 blur-3xl"></div>
                <div class="blob blob-delay absolute top-1/3 -right-32 h-[28rem] w-[28rem] rounded-full bg-fuchsia-400/15 dark:bg-fuchsia-500/10 blur-3xl"></div>
                <div class="blob absolute -bottom-32 left-1/4 h-96 w-96 rounded-full bg-sky-400/15 dark:bg-sky-500/10 blur-3xl"></div>
            </div>

            <div class="relative z-10 mx-auto max-w-7xl px-3 sm:px-6 lg:px-8">

                <!-- Header -->
                <div class="fade-up flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 pb-5 border-b border-slate-200/80 dark:border-slate-800/80">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h1 class="text-2xl font-bold tracking-tight leading-tight bg-gradient-to-r from-slate-900 via-indigo-700 to-violet-700 dark:from-white dark:via-indigo-200 dark:to-violet-300 bg-clip-text text-transparent">
                                Users Management
                            </h1>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                                Manage your users and their permissions
                            </p>
                        </div>
                    </div>
                    <button
                        @click="openCreateModal"
                        class="group inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/30 hover:shadow-xl hover:shadow-indigo-500/40 hover:-translate-y-0.5 active:translate-y-0 active:scale-95 transition-all duration-300 w-full sm:w-auto"
                    >
                        <PlusIcon class="w-5 h-5 transition-transform duration-300 group-hover:rotate-90" />
                        Add New User
                    </button>
                </div>

                <!-- ==================== STAT CARDS ==================== -->
                <div class="fade-up delay-1 grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
                    <div
                        v-for="card in statCards"
                        :key="card.key"
                        class="group relative overflow-hidden rounded-2xl border border-slate-200/70 dark:border-slate-800 bg-white/90 dark:bg-slate-900/80 backdrop-blur p-4 sm:p-5 shadow-lg shadow-slate-200/50 dark:shadow-black/20 hover:-translate-y-1 hover:shadow-xl transition-all duration-300"
                    >
                        <!-- Top accent line -->
                        <div :class="['absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r', card.accent]"></div>

                        <!-- Soft tint -->
                        <div :class="['pointer-events-none absolute inset-0 bg-gradient-to-br to-transparent opacity-70', card.tint]"></div>

                        <div class="relative flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs sm:text-[13px] font-semibold text-slate-500 dark:text-slate-400">
                                    {{ card.label }}
                                </p>
                                <p class="mt-1.5 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 dark:text-slate-50 tabular-nums">
                                    {{ card.value }}
                                </p>
                            </div>
                            <span :class="['flex h-11 w-11 sm:h-12 sm:w-12 shrink-0 items-center justify-center rounded-2xl text-white shadow-lg transition-transform duration-300 group-hover:scale-110 group-hover:rotate-6', card.iconBox]">
                                <component :is="card.icon" class="w-5 h-5 sm:w-6 sm:h-6" />
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="fade-up delay-1 bg-white/90 dark:bg-slate-900/80 backdrop-blur rounded-2xl shadow-lg shadow-slate-200/50 dark:shadow-black/20 border border-slate-200/70 dark:border-slate-800 p-4 mb-6 transition-all duration-300">
                    <div class="flex flex-col lg:flex-row gap-3 sm:gap-4">
                        <div class="flex-1 relative group">
                            <MagnifyingGlassIcon class="absolute left-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors duration-200" />
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search by name or email..."
                                class="w-full pl-11 pr-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                            />
                        </div>
                        <div class="flex flex-wrap gap-2 sm:gap-3">
                            <select
                                v-model="filterStatus"
                                class="flex-1 sm:flex-none px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-700 dark:text-white text-sm font-medium min-w-0 sm:min-w-[140px] cursor-pointer shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                            >
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <select
                                v-model="filterRole"
                                class="flex-1 sm:flex-none px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-700 dark:text-white text-sm font-medium min-w-0 sm:min-w-[140px] cursor-pointer shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                            >
                                <option value="">All Roles</option>
                                <option value="admin">Admin</option>
                                <option value="teacher">Teacher</option>
                                <option value="user">User</option>
                            </select>
                            <button
                                @click="resetFilters"
                                class="px-4 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-300 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 shadow-sm hover:bg-indigo-50 dark:hover:bg-slate-700 hover:border-indigo-200 hover:-translate-y-0.5 active:scale-95 transition-all duration-200 flex-shrink-0"
                            >
                                Reset
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="fade-up delay-2 bg-white/90 dark:bg-slate-900/80 backdrop-blur rounded-2xl shadow-lg shadow-slate-200/50 dark:shadow-black/20 border border-slate-200/70 dark:border-slate-800 overflow-hidden transition-all duration-300">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[800px]">
                            <thead>
                                <tr class="border-b border-slate-200/80 dark:border-slate-800 bg-gradient-to-r from-slate-50 to-indigo-50/40 dark:from-slate-900 dark:to-indigo-950/20">
                                    <th class="px-5 py-3.5 text-left">
                                        <button
                                            @click="sortBy('name')"
                                            class="group flex items-center gap-1 text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-indigo-600 dark:hover:text-indigo-300 transition-colors"
                                        >
                                            Name
                                            <ChevronUpDownIcon :class="['w-4 h-4 transition-colors', sortField === 'name' ? 'text-indigo-500' : 'text-slate-300 dark:text-slate-600 group-hover:text-indigo-400']" />
                                        </button>
                                    </th>
                                    <th class="px-5 py-3.5 text-left">
                                        <button
                                            @click="sortBy('email')"
                                            class="group flex items-center gap-1 text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-indigo-600 dark:hover:text-indigo-300 transition-colors"
                                        >
                                            Email
                                            <ChevronUpDownIcon :class="['w-4 h-4 transition-colors', sortField === 'email' ? 'text-indigo-500' : 'text-slate-300 dark:text-slate-600 group-hover:text-indigo-400']" />
                                        </button>
                                    </th>
                                    <th class="px-5 py-3.5 text-left">
                                        <button
                                            @click="sortBy('role')"
                                            class="group flex items-center gap-1 text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-indigo-600 dark:hover:text-indigo-300 transition-colors"
                                        >
                                            Role
                                            <ChevronUpDownIcon :class="['w-4 h-4 transition-colors', sortField === 'role' ? 'text-indigo-500' : 'text-slate-300 dark:text-slate-600 group-hover:text-indigo-400']" />
                                        </button>
                                    </th>
                                    <th class="px-5 py-3.5 text-left">
                                        <button
                                            @click="sortBy('status')"
                                            class="group flex items-center gap-1 text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-indigo-600 dark:hover:text-indigo-300 transition-colors"
                                        >
                                            Status
                                            <ChevronUpDownIcon :class="['w-4 h-4 transition-colors', sortField === 'status' ? 'text-indigo-500' : 'text-slate-300 dark:text-slate-600 group-hover:text-indigo-400']" />
                                        </button>
                                    </th>
                                    <th class="px-5 py-3.5 text-left">
                                        <button
                                            @click="sortBy('created_at')"
                                            class="group flex items-center gap-1 text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider hover:text-indigo-600 dark:hover:text-indigo-300 transition-colors"
                                        >
                                            Created
                                            <ChevronUpDownIcon :class="['w-4 h-4 transition-colors', sortField === 'created_at' ? 'text-indigo-500' : 'text-slate-300 dark:text-slate-600 group-hover:text-indigo-400']" />
                                        </button>
                                    </th>
                                    <th class="px-5 py-3.5 text-right">
                                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                            Actions
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!hasUsers" class="border-b border-slate-100 dark:border-slate-800">
                                    <td colspan="6" class="px-4 py-16 text-center text-slate-500 dark:text-slate-400">
                                        <div class="flex flex-col items-center gap-2">
                                            <span class="mb-1 flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 dark:bg-slate-800/60 text-3xl">👤</span>
                                            <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">No users found</p>
                                            <p class="text-xs">Try adjusting your filters or create a new user</p>
                                        </div>
                                    </td>
                                </tr>
                                <tr
                                    v-for="user in userList"
                                    :key="user.id"
                                    class="group border-b border-slate-100 dark:border-slate-800/70 last:border-0 hover:bg-indigo-50/50 dark:hover:bg-slate-800/40 transition-colors duration-200"
                                >
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center flex-shrink-0 shadow-md shadow-indigo-500/25 transition-transform duration-300 group-hover:scale-110">
                                                <span class="text-xs font-bold text-white">
                                                    {{ user.name.charAt(0).toUpperCase() }}
                                                </span>
                                            </div>
                                            <span class="text-sm font-semibold text-slate-800 dark:text-white truncate">
                                                {{ user.name }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="text-sm text-slate-600 dark:text-slate-300 truncate">
                                            {{ user.email }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span :class="['inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold capitalize ring-1 ring-inset ring-black/5 dark:ring-white/10', getRoleBadge(user.role)]">
                                            {{ user.role }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span :class="['inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold capitalize ring-1 ring-inset ring-black/5 dark:ring-white/10', getStatusBadge(user.status)]">
                                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                            {{ user.status }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="text-sm text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                            {{ new Date(user.created_at).toLocaleDateString('en-US', { 
                                                year: 'numeric', 
                                                month: 'short', 
                                                day: 'numeric' 
                                            }) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button
                                                @click="openEditModal(user)"
                                                class="p-2 text-blue-600 hover:text-white hover:bg-blue-600 bg-blue-50 dark:bg-blue-900/20 dark:text-blue-400 dark:hover:text-white dark:hover:bg-blue-600 rounded-lg hover:shadow-md hover:shadow-blue-500/30 hover:-translate-y-0.5 active:scale-90 transition-all duration-200"
                                                title="Edit user"
                                            >
                                                <PencilIcon class="w-4 h-4" />
                                            </button>
                                            <button
                                                @click="deleteUser(user)"
                                                class="p-2 text-red-600 hover:text-white hover:bg-red-600 bg-red-50 dark:bg-red-900/20 dark:text-red-400 dark:hover:text-white dark:hover:bg-red-600 rounded-lg hover:shadow-md hover:shadow-red-500/30 hover:-translate-y-0.5 active:scale-90 transition-all duration-200"
                                                title="Delete user"
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ✅ Pagination (backend links) -->
                    <div v-if="pagination && pagination.lastPage > 1" class="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-3.5 border-t border-slate-200/80 dark:border-slate-800 bg-gradient-to-r from-slate-50 to-indigo-50/30 dark:from-slate-900 dark:to-indigo-950/10">
                        <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 text-center sm:text-left">
                            Showing
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ pagination.from }}</span>
                            to
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ pagination.to }}</span>
                            of
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ pagination.total }}</span>
                            results
                        </div>
                        <div class="flex gap-1.5 flex-wrap justify-center">
                            <!-- Previous -->
                            <button
                                @click="goToUrl(pagination.prevUrl)"
                                :disabled="!pagination.prevUrl"
                                class="flex items-center justify-center px-2.5 sm:px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 hover:border-indigo-200 hover:-translate-y-0.5 active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:hover:bg-white dark:disabled:hover:bg-slate-800 transition-all duration-200 text-slate-600 dark:text-slate-300 shadow-sm"
                                title="Previous page"
                            >
                                <ChevronLeftIcon class="w-4 h-4" />
                            </button>

                            <!-- Page numbers / ellipsis (backend links) -->
                            <template v-for="(link, idx) in pagination.pageLinks" :key="idx">
                                <span
                                    v-if="!link.url"
                                    class="min-w-[34px] px-2.5 sm:px-3 py-1.5 text-sm font-semibold text-center text-slate-400 dark:text-slate-500 select-none"
                                >
                                    {{ link.label }}
                                </span>
                                <button
                                    v-else
                                    @click="goToUrl(link.url)"
                                    :class="[
                                        'min-w-[34px] px-2.5 sm:px-3 py-1.5 text-sm font-semibold rounded-lg transition-all duration-200 active:scale-95',
                                        link.active
                                            ? 'bg-gradient-to-br from-indigo-600 to-violet-600 text-white shadow-md shadow-indigo-500/30'
                                            : 'border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 hover:border-indigo-200 hover:-translate-y-0.5 text-slate-600 dark:text-slate-300 shadow-sm'
                                    ]"
                                >
                                    {{ link.label }}
                                </button>
                            </template>

                            <!-- Next -->
                            <button
                                @click="goToUrl(pagination.nextUrl)"
                                :disabled="!pagination.nextUrl"
                                class="flex items-center justify-center px-2.5 sm:px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 hover:border-indigo-200 hover:-translate-y-0.5 active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:hover:bg-white dark:disabled:hover:bg-slate-800 transition-all duration-200 text-slate-600 dark:text-slate-300 shadow-sm"
                                title="Next page"
                            >
                                <ChevronRightIcon class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="closeModals">
            <div class="flex min-h-screen items-start sm:items-center justify-center p-2 sm:p-4">
                <div class="modal-backdrop fixed inset-0 bg-slate-900/50 dark:bg-black/70 backdrop-blur-sm" @click="closeModals"></div>
                <div class="modal-pop relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl shadow-indigo-500/10 border border-slate-200/70 dark:border-slate-800 max-w-md w-full my-4 sm:my-0 overflow-hidden">

                    <!-- Modal header -->
                    <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-50/70 to-transparent dark:from-indigo-500/5">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
                                <PlusIcon class="w-5 h-5" />
                            </span>
                            <h2 class="text-lg sm:text-xl font-bold text-slate-800 dark:text-white">
                                Create New User
                            </h2>
                        </div>
                        <button @click="closeModals" class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg hover:rotate-90 transition-all duration-300">
                            <XMarkIcon class="w-5 h-5 text-slate-500 dark:text-slate-400" />
                        </button>
                    </div>

                    <form @submit.prevent="createUser" class="space-y-4 px-5 sm:px-6 py-5">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Name *
                            </label>
                            <input
                                v-model="createForm.name"
                                type="text"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.name }"
                            />
                            <p v-if="errors.name" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.name }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Email *
                            </label>
                            <input
                                v-model="createForm.email"
                                type="email"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.email }"
                            />
                            <p v-if="errors.email" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.email }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Password *
                            </label>
                            <input
                                v-model="createForm.password"
                                type="password"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.password }"
                            />
                            <p v-if="errors.password" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.password }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Role *
                            </label>
                            <select
                                v-model="createForm.role"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm cursor-pointer shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.role }"
                            >
                                <option value="">Select role</option>
                                <option value="admin">Admin</option>
                                <option value="teacher">Teacher</option>
                                <option value="user">User</option>
                            </select>
                            <p v-if="errors.role" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.role }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Status *
                            </label>
                            <select
                                v-model="createForm.status"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm cursor-pointer shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.status }"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <p v-if="errors.status" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.status }}</p>
                        </div>

                        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 sm:gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                            <button
                                type="button"
                                @click="closeModals"
                                class="w-full sm:w-auto px-4 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-white border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 active:scale-95 transition-all duration-200"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="createForm.processing"
                                class="w-full sm:w-auto px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/30 hover:shadow-xl hover:-translate-y-0.5 active:translate-y-0 active:scale-95 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0"
                            >
                                <span v-if="createForm.processing">Creating...</span>
                                <span v-else>Create User</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="closeModals">
            <div class="flex min-h-screen items-start sm:items-center justify-center p-2 sm:p-4">
                <div class="modal-backdrop fixed inset-0 bg-slate-900/50 dark:bg-black/70 backdrop-blur-sm" @click="closeModals"></div>
                <div class="modal-pop relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl shadow-indigo-500/10 border border-slate-200/70 dark:border-slate-800 max-w-md w-full my-4 sm:my-0 overflow-hidden">

                    <!-- Modal header -->
                    <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-50/70 to-transparent dark:from-blue-500/5">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-lg shadow-blue-500/30">
                                <PencilIcon class="w-[18px] h-[18px]" />
                            </span>
                            <h2 class="text-lg sm:text-xl font-bold text-slate-800 dark:text-white">
                                Edit User
                            </h2>
                        </div>
                        <button @click="closeModals" class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg hover:rotate-90 transition-all duration-300">
                            <XMarkIcon class="w-5 h-5 text-slate-500 dark:text-slate-400" />
                        </button>
                    </div>

                    <form @submit.prevent="updateUser" class="space-y-4 px-5 sm:px-6 py-5">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Name *
                            </label>
                            <input
                                v-model="editForm.name"
                                type="text"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.name }"
                            />
                            <p v-if="errors.name" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.name }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Email *
                            </label>
                            <input
                                v-model="editForm.email"
                                type="email"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.email }"
                            />
                            <p v-if="errors.email" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.email }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Password (leave blank to keep current)
                            </label>
                            <input
                                v-model="editForm.password"
                                type="password"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.password }"
                            />
                            <p v-if="errors.password" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.password }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Role *
                            </label>
                            <select
                                v-model="editForm.role"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm cursor-pointer shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.role }"
                            >
                                <option value="">Select role</option>
                                <option value="admin">Admin</option>
                                <option value="teacher">Teacher</option>
                                <option value="user">User</option>
                            </select>
                            <p v-if="errors.role" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.role }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Status *
                            </label>
                            <select
                                v-model="editForm.status"
                                class="w-full px-3.5 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/70 dark:bg-slate-800/70 text-slate-800 dark:text-white text-sm cursor-pointer shadow-sm hover:border-indigo-300 dark:hover:border-slate-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-indigo-500/15 focus:border-indigo-500 outline-none transition-all duration-200"
                                :class="{ 'border-red-500 dark:border-red-500': errors.status }"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <p v-if="errors.status" class="mt-1.5 text-sm text-red-500 dark:text-red-400">{{ errors.status }}</p>
                        </div>

                        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 sm:gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                            <button
                                type="button"
                                @click="closeModals"
                                class="w-full sm:w-auto px-4 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-white border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 active:scale-95 transition-all duration-200"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="editForm.processing"
                                class="w-full sm:w-auto px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/30 hover:shadow-xl hover:-translate-y-0.5 active:translate-y-0 active:scale-95 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0"
                            >
                                <span v-if="editForm.processing">Updating...</span>
                                <span v-else>Update User</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* Smooth entrance animation */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.fade-up {
    animation: fadeUp 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.delay-1 { animation-delay: 0.12s; }
.delay-2 { animation-delay: 0.24s; }

/* Modal animations */
@keyframes backdropIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}
.modal-backdrop {
    animation: backdropIn 0.25s ease both;
}
@keyframes modalPop {
    from { opacity: 0; transform: translateY(14px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-pop {
    animation: modalPop 0.3s cubic-bezier(0.22, 1, 0.36, 1) both;
}

/* Toast transition */
.toast-enter-active {
    transition: all 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
.toast-leave-active {
    transition: all 0.25s ease-in;
}
.toast-enter-from {
    opacity: 0;
    transform: translateX(24px) scale(0.96);
}
.toast-leave-to {
    opacity: 0;
    transform: translateX(24px) scale(0.96);
}

/* Floating background blobs */
@keyframes floatBlob {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50%      { transform: translate(24px, -28px) scale(1.08); }
}
.blob {
    animation: floatBlob 14s ease-in-out infinite;
}
.blob-delay {
    animation-delay: -6s;
    animation-duration: 18s;
}

/* Respect reduced-motion preference */
@media (prefers-reduced-motion: reduce) {
    .fade-up, .modal-backdrop, .modal-pop, .blob {
        animation: none !important;
    }
    .toast-enter-active, .toast-leave-active {
        transition: none !important;
    }
}
</style>