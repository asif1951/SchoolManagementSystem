<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
    galleries: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }),
    },
    filters: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
})

const page = usePage()

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
// STATE
// ============================================================
const showForm = ref(false)
const editingId = ref(null)
const previewUrl = ref(null)

const form = useForm({
    title: '',
    description: '',
    image: null,
    _method: 'post',
})

// ============================================================
// HELPERS
// ============================================================
const galleriesList = computed(() => props.galleries?.data || [])

const imageUrl = (path) => {
    if (!path) return null
    if (path.startsWith('http')) return path
    return `/storage/${path.replace(/^\/+/, '')}`
}

// ============================================================
// FILTERS (backend-driven)
// ============================================================
const search  = ref(props.filters?.search ?? '')
const sort    = ref(props.filters?.sort ?? 'latest')
const perPage = ref(props.filters?.per_page ?? 12)

// Keep local state in sync when server sends new filters (e.g. after back/forward)
watch(() => props.filters, (f) => {
    search.value  = f?.search ?? ''
    sort.value    = f?.sort ?? 'latest'
    perPage.value = f?.per_page ?? 12
}, { deep: true })

let searchTimer = null

const applyFilters = () => {
    router.get(
        route('galleries.index'),
        {
            search: search.value || undefined,
            sort: sort.value,
            per_page: perPage.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['galleries', 'filters'],
        }
    )
}

const onSearchInput = () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(applyFilters, 350)
}

const onSortChange = () => applyFilters()
const onPerPageChange = () => applyFilters()

const clearFilters = () => {
    search.value = ''
    sort.value = 'latest'
    perPage.value = 12
    applyFilters()
}

const hasActiveFilters = computed(
    () => !!search.value || sort.value !== 'latest' || perPage.value !== 12
)

// ============================================================
// OPEN / CLOSE FORM
// ============================================================
const openCreateForm = () => {
    editingId.value = null
    form.reset()
    form.clearErrors()
    form._method = 'post'
    previewUrl.value = null
    showForm.value = true
}

const openEditForm = (item) => {
    editingId.value = item.id
    form.clearErrors()
    form.title = item.title ?? ''
    form.description = item.description ?? ''
    form.image = null
    form._method = 'put'
    previewUrl.value = imageUrl(item.image)
    showForm.value = true
}

const closeForm = () => {
    showForm.value = false
    editingId.value = null
    form.reset()
    form.clearErrors()
    previewUrl.value = null
}

// ============================================================
// FILE HANDLE
// ============================================================
const handleFile = (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    form.image = file
    if (previewUrl.value && previewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(previewUrl.value)
    }
    previewUrl.value = URL.createObjectURL(file)
}

// ============================================================
// SUBMIT — CREATE / UPDATE
// ============================================================
const submit = () => {
    if (editingId.value) {
        form._method = 'put'
        form.post(route('galleries.update', editingId.value), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                closeForm()
            },
            onError: () => showToast('Please fix the errors below.', 'error'),
        })
    } else {
        form._method = 'post'
        form.post(route('galleries.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                closeForm()
            },
            onError: () => showToast('Please fix the errors below.', 'error'),
        })
    }
}

// ============================================================
// DELETE
// ============================================================
const deletingId = ref(null)

const confirmDelete = (item) => {
    if (confirm(`Delete "${item.title}"? This cannot be undone.`)) {
        deletingId.value = item.id
        router.delete(route('galleries.destroy', item.id), {
            preserveScroll: true,
            onFinish: () => { deletingId.value = null },
        })
    }
}

// ============================================================
// CLEANUP
// ============================================================
onBeforeUnmount(() => {
    clearTimeout(searchTimer)
    if (previewUrl.value && previewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(previewUrl.value)
    }
})
</script>

<template>
    <Head title="Gallery Management" />

    <AuthenticatedLayout>
        <div class="w-full px-3 py-4 sm:px-4 sm:py-6 md:px-6 lg:px-8 bg-gradient-to-br from-indigo-50/30 via-white to-purple-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 min-h-screen transition-colors duration-300">

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

            <!-- ═══ HEADER ═══ -->
            <div class="mb-5 sm:mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="p-2.5 sm:p-3 bg-gradient-to-br from-pink-400 to-purple-500 rounded-2xl shadow-lg shadow-purple-500/20 flex-shrink-0">
                        <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-indigo-800 to-purple-800 dark:from-indigo-300 dark:to-purple-300 bg-clip-text text-transparent truncate">
                            Gallery Management
                        </h1>
                        <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400 mt-1">
                            Add, edit, or remove gallery images
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    @click="openCreateForm"
                    class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-pink-500 to-purple-600 hover:from-pink-600 hover:to-purple-700 px-4 sm:px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-purple-500/30 transition active:scale-95"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                    </svg>
                    Add New Image
                </button>
            </div>

            <!-- ═══ FILTER BAR (backend) ═══ -->
            <div class="mb-4 sm:mb-5 bg-white dark:bg-slate-900 rounded-2xl border-2 border-indigo-200/60 dark:border-indigo-800/60 shadow-sm p-3 sm:p-4 transition-colors duration-300">
                <div class="flex flex-col md:flex-row md:items-center gap-3">

                    <!-- Search -->
                    <div class="relative flex-1 min-w-0">
                        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-indigo-400 dark:text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                        </svg>
                        <input
                            v-model="search"
                            @input="onSearchInput"
                            type="text"
                            placeholder="Search by title or description..."
                            class="w-full rounded-xl border-2 border-indigo-200 dark:border-slate-700 bg-white dark:bg-slate-800 pl-9 pr-3 py-2.5 text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                        />
                    </div>

                    <!-- Sort -->
                    <select
                        v-model="sort"
                        @change="onSortChange"
                        class="rounded-xl border-2 border-indigo-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm font-semibold text-indigo-700 dark:text-indigo-300 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 cursor-pointer"
                    >
                        <option value="latest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="title_asc">Title A → Z</option>
                        <option value="title_desc">Title Z → A</option>
                    </select>

                    <!-- Per page -->
                    <select
                        v-model="perPage"
                        @change="onPerPageChange"
                        class="rounded-xl border-2 border-indigo-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm font-semibold text-indigo-700 dark:text-indigo-300 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 cursor-pointer"
                    >
                        <option :value="12">12 / page</option>
                        <option :value="24">24 / page</option>
                        <option :value="48">48 / page</option>
                        <option :value="96">96 / page</option>
                    </select>

                    <!-- Clear -->
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        @click="clearFilters"
                        class="inline-flex items-center justify-center gap-1.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition active:scale-95"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Clear
                    </button>
                </div>

                <!-- Active filter hint -->
                <div v-if="hasActiveFilters" class="mt-2.5 flex flex-wrap items-center gap-2 text-xs text-indigo-500 dark:text-indigo-400">
                    <span v-if="search">Search: <span class="font-bold text-indigo-700 dark:text-indigo-200">"{{ search }}"</span></span>
                    <span v-if="sort !== 'latest'">· Sort: <span class="font-bold text-indigo-700 dark:text-indigo-200">{{ sort }}</span></span>
                    <span v-if="perPage !== 12">· <span class="font-bold text-indigo-700 dark:text-indigo-200">{{ perPage }}</span> per page</span>
                </div>
            </div>

            <!-- ═══ FORM MODAL ═══ -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="showForm"
                    class="fixed inset-0 z-50 flex items-start sm:items-center justify-center bg-slate-900/60 dark:bg-black/70 backdrop-blur-sm p-3 sm:p-6 overflow-y-auto"
                    @click.self="closeForm"
                >
                    <div class="w-full max-w-xl rounded-2xl bg-white dark:bg-slate-800 shadow-2xl border border-indigo-100 dark:border-slate-700 overflow-hidden my-auto transition-colors duration-300">
                        <!-- Modal Header -->
                        <div class="flex items-center justify-between px-5 py-4 border-b border-indigo-100 dark:border-slate-700 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-950/40 dark:to-purple-950/40">
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-indigo-900 dark:text-indigo-100">
                                    {{ editingId ? 'Edit Gallery Image' : 'Add New Gallery Image' }}
                                </h2>
                                <p class="text-xs text-indigo-500 dark:text-indigo-400 mt-0.5">
                                    {{ editingId ? 'Update the details below' : 'Fill the fields to upload a new image' }}
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="closeForm"
                                class="rounded-lg p-1.5 text-slate-500 dark:text-slate-400 hover:bg-white/60 dark:hover:bg-slate-700 transition"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Modal Body -->
                        <form @submit.prevent="submit" class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">

                            <!-- Title -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                    Image Title <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    v-model="form.title"
                                    type="text"
                                    placeholder="e.g. Annual Sports Day 2026"
                                    class="w-full rounded-xl border-2 border-indigo-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3.5 py-2.5 text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                                    :class="{ 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/10': form.errors.title }"
                                />
                                <p v-if="form.errors.title" class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ form.errors.title }}</p>
                            </div>

                            <!-- Image upload -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                    Upload Picture <span v-if="!editingId" class="text-rose-500">*</span>
                                </label>

                                <label
                                    class="relative flex flex-col items-center justify-center w-full min-h-[140px] rounded-xl border-2 border-dashed border-indigo-300 dark:border-indigo-700 bg-indigo-50/40 dark:bg-indigo-950/30 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 transition cursor-pointer overflow-hidden"
                                    :class="{ 'border-rose-400 bg-rose-50/40 dark:bg-rose-950/30': form.errors.image }"
                                >
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/jpg,image/webp"
                                        class="hidden"
                                        @change="handleFile"
                                    />

                                    <img
                                        v-if="previewUrl"
                                        :src="previewUrl"
                                        alt="Preview"
                                        class="absolute inset-0 w-full h-full object-cover"
                                    />

                                    <div v-if="!previewUrl" class="flex flex-col items-center gap-2 py-6 px-4 text-center">
                                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-white dark:bg-slate-800 shadow-sm">
                                            <svg class="h-6 w-6 text-indigo-500 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                        <p class="text-sm font-semibold text-indigo-800 dark:text-indigo-200">Choose Image</p>
                                        <p class="text-xs text-indigo-500 dark:text-indigo-400">JPG, PNG, WEBP · max 4MB</p>
                                    </div>

                                    <div v-else class="absolute bottom-2 right-2 rounded-lg bg-black/60 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm">
                                        Click to change
                                    </div>
                                </label>
                                <p v-if="form.errors.image" class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ form.errors.image }}</p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                    Description <span class="text-xs font-normal text-slate-400 dark:text-slate-500">(Optional)</span>
                                </label>
                                <textarea
                                    v-model="form.description"
                                    rows="3"
                                    placeholder="Write a short description..."
                                    class="w-full rounded-xl border-2 border-indigo-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3.5 py-2.5 text-sm text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 resize-none"
                                    :class="{ 'border-rose-400': form.errors.description }"
                                ></textarea>
                                <p v-if="form.errors.description" class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ form.errors.description }}</p>
                            </div>
                        </form>

                        <!-- Modal Footer -->
                        <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-indigo-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60">
                            <button
                                type="button"
                                @click="closeForm"
                                class="rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-2.5 text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition active:scale-95"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                @click="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-pink-500 to-purple-600 hover:from-pink-600 hover:to-purple-700 px-5 py-2.5 text-sm font-bold text-white shadow-md shadow-purple-500/30 transition active:scale-95 disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                </svg>
                                {{ form.processing ? 'Saving...' : (editingId ? 'Update Image' : 'Save Gallery Image') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ═══ EMPTY STATE ═══ -->
            <div v-if="galleriesList.length === 0" class="bg-white dark:bg-slate-900 rounded-2xl p-10 sm:p-16 border-2 border-indigo-200/60 dark:border-indigo-800/60 shadow-sm text-center transition-colors duration-300">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-100 to-purple-100 dark:from-indigo-900/50 dark:to-purple-900/50">
                    <svg class="h-8 w-8 text-indigo-400 dark:text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>

                <template v-if="hasActiveFilters">
                    <h3 class="text-base sm:text-lg font-bold text-indigo-800 dark:text-indigo-200 mb-1">No matching images</h3>
                    <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400 mb-5">Try a different search or clear the filters.</p>
                    <button
                        type="button"
                        @click="clearFilters"
                        class="inline-flex items-center gap-2 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-slate-800 px-5 py-2.5 text-sm font-bold text-indigo-700 dark:text-indigo-300 transition active:scale-95"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Clear Filters
                    </button>
                </template>

                <template v-else>
                    <h3 class="text-base sm:text-lg font-bold text-indigo-800 dark:text-indigo-200 mb-1">No Gallery Images Yet</h3>
                    <p class="text-xs sm:text-sm text-indigo-500 dark:text-indigo-400 mb-5">Upload your first image to get started.</p>
                    <button
                        type="button"
                        @click="openCreateForm"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-pink-500 to-purple-600 px-5 py-2.5 text-sm font-bold text-white shadow-md shadow-purple-500/30 transition active:scale-95"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                        </svg>
                        Add First Image
                    </button>
                </template>
            </div>

            <!-- ═══ GRID ═══ -->
            <div v-else>
                <div class="mb-3 flex items-center gap-2 flex-wrap">
                    <h2 class="text-base sm:text-lg font-bold text-indigo-800 dark:text-indigo-200">All Images</h2>
                    <span class="text-xs bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 px-2.5 py-1 rounded-full font-semibold">
                        {{ galleries.total || galleriesList.length }} total
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-5">
                    <div
                        v-for="item in galleriesList"
                        :key="item.id"
                        class="group relative bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200/50 dark:border-slate-800 shadow-sm hover:shadow-xl hover:border-purple-300 dark:hover:border-purple-700 transition-all duration-300 overflow-hidden flex flex-col"
                    >
                        <!-- ✅ Image — clean, no overlay, full clear -->
                        <div class="relative aspect-[4/3] bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <img
                                v-if="item.image"
                                :src="imageUrl(item.image)"
                                :alt="item.title"
                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center text-slate-300 dark:text-slate-600">
                                <svg class="h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                        </div>

                        <!-- ✅ Info + Actions (always visible, no hover needed) -->
                        <div class="p-4 flex-1 flex flex-col">
                            <div class="flex items-start justify-between gap-2 mb-1">
                                <h3 class="text-sm sm:text-base font-bold text-indigo-900 dark:text-indigo-100 truncate flex-1" :title="item.title">
                                    {{ item.title }}
                                </h3>
                            </div>

                            <p v-if="item.description" class="text-xs text-slate-500 dark:text-slate-400 line-clamp-2 mb-3">
                                {{ item.description }}
                            </p>
                            <p v-else class="text-xs text-slate-300 dark:text-slate-600 italic mb-3">No description</p>

                            <!-- ✅ Action buttons — always visible on all devices -->
                            <div class="mt-auto pt-3 border-t border-indigo-100 dark:border-slate-800 flex items-center justify-between gap-2">
                                <span class="text-[11px] text-slate-400 dark:text-slate-500 truncate">
                                    {{ item.created_at || '' }}
                                </span>

                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button
                                        type="button"
                                        @click="openEditForm(item)"
                                        class="inline-flex items-center gap-1 rounded-lg bg-indigo-50 dark:bg-indigo-900/40 border border-indigo-200 dark:border-indigo-800 px-2.5 py-1.5 text-[11px] font-bold text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 transition active:scale-95"
                                        title="Edit"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        @click="confirmDelete(item)"
                                        :disabled="deletingId === item.id"
                                        class="inline-flex items-center gap-1 rounded-lg bg-rose-50 dark:bg-rose-900/40 border border-rose-200 dark:border-rose-800 px-2.5 py-1.5 text-[11px] font-bold text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/60 transition active:scale-95 disabled:opacity-60"
                                        title="Delete"
                                    >
                                        <svg v-if="deletingId === item.id" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        <svg v-else class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" />
                                        </svg>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ PAGINATION ═══ -->
                <div v-if="galleries.links && galleries.links.length > 3" class="mt-5 bg-white dark:bg-slate-900 rounded-2xl border-2 border-indigo-200/60 dark:border-indigo-800/60 shadow-sm px-3 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-center justify-between gap-3 transition-colors duration-300">
                    <div class="text-xs sm:text-sm text-indigo-600 dark:text-indigo-400 text-center sm:text-left">
                        Showing <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ galleries.from }}</span> to
                        <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ galleries.to }}</span> of
                        <span class="font-bold text-indigo-800 dark:text-indigo-200">{{ galleries.total }}</span> images
                    </div>
                    <div class="flex items-center gap-1 flex-wrap justify-center">
                        <template v-for="(link, index) in galleries.links" :key="index">
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
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>