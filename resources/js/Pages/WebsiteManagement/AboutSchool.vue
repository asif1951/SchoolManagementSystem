<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps({
    about: { type: Object, default: () => ({}) },
})

const page = usePage()

// ============================================================
// ORIGINAL — from DB
// ============================================================
const original = {
    about_title:       props.about?.about_title ?? '',
    about_subtitle:    props.about?.about_subtitle ?? '',
    about_description: props.about?.about_description ?? '',
    school_history:    props.about?.school_history ?? '',
    mission:           props.about?.mission ?? '',
    vision:            props.about?.vision ?? '',
    core_values:       props.about?.core_values ?? '',
}

// ============================================================
// FORM
// ============================================================
const form = useForm({
    about_title:       original.about_title,
    about_subtitle:    original.about_subtitle,
    about_description: original.about_description,
    school_history:    original.school_history,
    mission:           original.mission,
    vision:            original.vision,
    core_values:       original.core_values,
    about_image:       null,
})

// ============================================================
// FIELD LABELS
// ============================================================
const fieldLabels = {
    about_title:       'About Title',
    about_subtitle:    'About Subtitle',
    about_description: 'About Description',
    school_history:    'School History',
    mission:           'Mission',
    vision:            'Vision',
    core_values:       'Core Values',
    about_image:       'About Image',
}

// ============================================================
// EXISTING IMAGE — from DB
// ============================================================
const existingImage = computed(() => {
    const path = props.about?.about_image
    if (!path) return null
    return `/storage/${path}`
})

// ============================================================
// IMAGE HANDLING
// ============================================================
const imagePreview = ref(null)
const isDragging = ref(false)
const fileInputRef = ref(null)

const handleFile = (file) => {
    if (!file) return
    form.about_image = file
    imagePreview.value = URL.createObjectURL(file)
}

const onImageChange = (e) => handleFile(e.target.files?.[0])

const onDrop = (e) => {
    e.preventDefault()
    isDragging.value = false
    const file = e.dataTransfer?.files?.[0]
    if (file) handleFile(file)
}

const onDragOver = (e) => { e.preventDefault(); isDragging.value = true }
const onDragLeave = () => { isDragging.value = false }
const openFilePicker = () => fileInputRef.value?.click()

const removeImage = () => {
    form.about_image = null
    imagePreview.value = null
    if (fileInputRef.value) fileInputRef.value.value = ''
}

// ============================================================
// INLINE TOAST
// ============================================================
const toast = ref({ show: false, message: '', type: 'success' })
let toastTimeout = null

const showToast = (message, type = 'success') => {
    if (toastTimeout) clearTimeout(toastTimeout)
    toast.value = { show: true, message, type }
    toastTimeout = setTimeout(() => (toast.value.show = false), 3500)
}

onMounted(() => {
    if (page.props.flash?.success) showToast(page.props.flash.success, 'success')
    if (page.props.flash?.error) showToast(page.props.flash.error, 'error')
})

// ============================================================
// WATCH FOR CHANGES
// ============================================================
const watchedFields = [
    'about_title', 'about_subtitle', 'about_description',
    'school_history', 'mission', 'vision', 'core_values',
]

watchedFields.forEach((field) => {
    watch(() => form[field], (newVal, oldVal) => {
        if (newVal === oldVal) return
        const label = fieldLabels[field] ?? field
        const origVal = original[field] ?? ''

        if (String(newVal).trim() === String(origVal).trim()) {
            showToast(`${label} reverted to original value`, 'info')
            return
        }
        showToast(`${label} updated`, 'info')
    })
})

watch(() => form.about_image, (newFile) => {
    if (newFile) showToast('About image selected — click Save to apply', 'info')
})

// ============================================================
// SUBMIT
// ============================================================
const submit = () => {
    form.post(route('website-management.about-school.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: (pageResp) => {
            showToast(
                pageResp.props.flash?.success || 'About page saved successfully! ✅',
                'success'
            )
            Object.keys(original).forEach((key) => {
                if (key in form) original[key] = form[key]
            })
            if (fileInputRef.value) fileInputRef.value.value = ''
        },
        onError: (errors) => {
            const first = Object.values(errors)[0] || 'Please fix the errors and try again.'
            showToast(first, 'error')
        },
    })
}
</script>

<template>
    <Head title="About School Management" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-blue-50/40 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 px-3 py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 transition-colors duration-300">
            <!-- Decorative blobs - overflow-hidden wrapper এর ভিতরে রাখা হয়েছে -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -left-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-blue-200/30 dark:bg-blue-800/10 blur-3xl"></div>
                <div class="absolute top-1/3 -right-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-cyan-200/30 dark:bg-cyan-800/10 blur-3xl"></div>
            </div>

            <div class="relative w-full">

                <!-- INLINE TOAST -->
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
                        :class="[
                            'mb-4 sm:mb-6 flex items-start gap-3 rounded-2xl border-2 p-3 sm:p-4 shadow-lg backdrop-blur-sm',
                            toast.type === 'success' ? 'bg-emerald-50/90 dark:bg-emerald-900/40 border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200' : '',
                            toast.type === 'error'   ? 'bg-rose-50/90 dark:bg-rose-900/40 border-rose-300 dark:border-rose-700 text-rose-800 dark:text-rose-200' : '',
                            toast.type === 'info'    ? 'bg-blue-50/90 dark:bg-blue-900/40 border-blue-300 dark:border-blue-700 text-blue-800 dark:text-blue-200' : '',
                        ]"
                    >
                        <span class="text-xl flex-shrink-0 leading-none mt-0.5">
                            {{ toast.type === 'success' ? '✅' : toast.type === 'error' ? '❌' : 'ℹ️' }}
                        </span>
                        <p class="text-xs sm:text-sm font-semibold flex-1 break-words leading-relaxed">
                            {{ toast.message }}
                        </p>
                        <button
                            type="button"
                            @click="toast.show = false"
                            class="flex-shrink-0 rounded-lg p-1 transition text-slate-400 dark:text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 hover:text-slate-700 dark:hover:text-slate-200"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </Transition>

                <form @submit.prevent="submit" class="space-y-4 sm:space-y-6">

                    <!-- ============================================= -->
                    <!-- 📖 ABOUT CONTENT -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-50/80 via-white to-cyan-50/60 dark:from-blue-950/30 dark:via-slate-900 dark:to-cyan-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-cyan-600 shadow-lg shadow-blue-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">📖 About Content</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Main about section shown on your public website</p>
                            </div>
                        </header>

                        <div class="grid grid-cols-1 gap-4 sm:gap-6 p-3 sm:p-6">
                            <!-- About Title -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    About Title <span class="text-rose-500">*</span>
                                </label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-blue-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.about_title"
                                        type="text"
                                        placeholder="e.g. About Our School"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                    />
                                </div>
                                <p v-if="form.errors.about_title" class="mt-1.5 text-xs font-medium text-rose-500 dark:text-rose-400">
                                    {{ form.errors.about_title }}
                                </p>
                            </div>

                            <!-- About Subtitle -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    About Subtitle
                                </label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-blue-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.about_subtitle"
                                        type="text"
                                        placeholder="e.g. Building a Better Future Through Quality Education"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                    />
                                </div>
                            </div>

                            <!-- About Description -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    About Description
                                </label>
                                <div class="relative">
                                    <textarea
                                        v-model="form.about_description"
                                        rows="5"
                                        placeholder="Write a detailed description about your school..."
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                    ></textarea>
                                    <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 dark:text-slate-500 bg-white/70 dark:bg-slate-900/70 px-1.5 py-0.5 rounded">
                                        {{ form.about_description?.length || 0 }} chars
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 🖼️ ABOUT IMAGE -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-50/80 via-white to-teal-50/60 dark:from-emerald-950/30 dark:via-slate-900 dark:to-teal-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🖼️ About Image</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Featured image for the about section</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6 space-y-4 sm:space-y-5">
                            <div
                                @drop="onDrop"
                                @dragover="onDragOver"
                                @dragleave="onDragLeave"
                                @click="openFilePicker"
                                :class="[
                                    'group relative cursor-pointer overflow-hidden rounded-2xl border-2 border-dashed transition-all duration-300',
                                    isDragging
                                        ? 'border-emerald-500 bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-950/40 dark:to-teal-950/40 scale-[1.01] shadow-xl shadow-emerald-200/50 dark:shadow-emerald-900/30'
                                        : 'border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 hover:border-emerald-400 hover:bg-emerald-50/40 dark:hover:bg-emerald-950/20',
                                ]"
                            >
                                <template v-if="imagePreview || existingImage">
                                    <div class="relative aspect-[16/9] sm:aspect-[21/9] w-full overflow-hidden bg-slate-900">
                                        <img
                                            :src="imagePreview || existingImage"
                                            alt="About school"
                                            class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                        />
                                        <div class="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-slate-900/20 to-transparent"></div>

                                        <div class="absolute inset-0 flex flex-col justify-between p-4 sm:p-6">
                                            <div class="flex justify-end">
                                                <button
                                                    type="button"
                                                    @click.stop="removeImage"
                                                    class="inline-flex items-center gap-1.5 rounded-xl bg-rose-500/90 backdrop-blur-sm px-3 py-2 text-xs font-bold text-white shadow-lg transition hover:bg-rose-600 hover:scale-105 active:scale-95"
                                                >
                                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                                    </svg>
                                                    Remove
                                                </button>
                                            </div>

                                            <div class="min-w-0">
                                                <p class="text-xs sm:text-sm font-bold text-white truncate">
                                                    {{ form.about_image ? 'New image selected' : 'Current about image' }}
                                                </p>
                                                <p class="text-[10px] sm:text-xs text-white/70 truncate">
                                                    Click to replace · Drag & drop to change
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <template v-else>
                                    <div class="flex flex-col items-center justify-center gap-3 sm:gap-4 px-4 py-10 sm:py-14 text-center">
                                        <div :class="[
                                            'flex h-16 w-16 sm:h-20 sm:w-20 items-center justify-center rounded-3xl transition-all duration-300',
                                            isDragging
                                                ? 'bg-gradient-to-br from-emerald-500 to-teal-600 shadow-xl shadow-emerald-500/40 scale-110'
                                                : 'bg-gradient-to-br from-emerald-100 to-teal-100 dark:from-emerald-900/50 dark:to-teal-900/50 group-hover:from-emerald-200 group-hover:to-teal-200 dark:group-hover:from-emerald-900/70 dark:group-hover:to-teal-900/70 group-hover:scale-110',
                                        ]">
                                            <svg :class="['h-8 w-8 sm:h-10 sm:w-10 transition-colors', isDragging ? 'text-white' : 'text-emerald-600 dark:text-emerald-400']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                    d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100">
                                                {{ isDragging ? 'Drop your image here!' : 'Upload about image' }}
                                            </p>
                                            <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 mt-1">Drag & drop or click to browse</p>
                                        </div>
                                        <div class="flex flex-wrap items-center justify-center gap-1.5 mt-1">
                                            <span class="rounded-full bg-emerald-100 dark:bg-emerald-900/40 px-2.5 py-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">JPG</span>
                                            <span class="rounded-full bg-emerald-100 dark:bg-emerald-900/40 px-2.5 py-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">PNG</span>
                                            <span class="rounded-full bg-emerald-100 dark:bg-emerald-900/40 px-2.5 py-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">WEBP</span>
                                            <span class="rounded-full bg-blue-100 dark:bg-blue-900/40 px-2.5 py-1 text-[10px] font-bold text-blue-700 dark:text-blue-300">✓ Any size</span>
                                        </div>
                                    </div>
                                </template>

                                <input
                                    ref="fileInputRef"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onImageChange"
                                />
                            </div>

                            <p class="text-[11px] sm:text-xs text-slate-400 dark:text-slate-500">
                                Recommended aspect ratio: <strong>16:9</strong> for best display.
                            </p>
                            <p v-if="form.errors.about_image" class="text-xs font-medium text-rose-500 dark:text-rose-400">
                                {{ form.errors.about_image }}
                            </p>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 📜 SCHOOL HISTORY -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-50/80 via-white to-orange-50/60 dark:from-amber-950/30 dark:via-slate-900 dark:to-orange-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 shadow-lg shadow-amber-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">📜 School History</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">The story and background of your school</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6">
                            <div class="relative">
                                <textarea
                                    v-model="form.school_history"
                                    rows="5"
                                    placeholder="Write about your school's history, how it was founded, and its journey..."
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-amber-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-amber-100 dark:focus:ring-amber-900/30"
                                ></textarea>
                                <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 dark:text-slate-500 bg-white/70 dark:bg-slate-900/70 px-1.5 py-0.5 rounded">
                                    {{ form.school_history?.length || 0 }} chars
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 🎯 MISSION / VISION / VALUES -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-purple-50/80 via-white to-pink-50/60 dark:from-purple-950/30 dark:via-slate-900 dark:to-pink-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-purple-500 to-pink-600 shadow-lg shadow-purple-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.05 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🎯 Mission, Vision & Values</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Core principles of your institution</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6 space-y-5 sm:space-y-6">
                            <!-- Mission -->
                            <div class="rounded-2xl border-2 border-indigo-100 dark:border-indigo-800 bg-gradient-to-br from-indigo-50/40 to-white dark:from-indigo-950/30 dark:to-slate-900 p-3 sm:p-5 transition-colors duration-300">
                                <div class="mb-3 flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-600 text-white text-xs font-bold shadow-sm">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                    </span>
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Our Mission</span>
                                    <span class="rounded-full bg-indigo-100 dark:bg-indigo-900/40 px-2 py-0.5 text-[9px] font-bold text-indigo-700 dark:text-indigo-300 uppercase tracking-wider">Mission</span>
                                </div>
                                <div class="relative">
                                    <textarea
                                        v-model="form.mission"
                                        rows="4"
                                        placeholder="Describe your school's mission — what you aim to achieve..."
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-indigo-100 dark:focus:ring-indigo-900/30"
                                    ></textarea>
                                    <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 dark:text-slate-500 bg-white/70 dark:bg-slate-900/70 px-1.5 py-0.5 rounded">
                                        {{ form.mission?.length || 0 }} chars
                                    </div>
                                </div>
                            </div>

                            <!-- Vision -->
                            <div class="rounded-2xl border-2 border-emerald-100 dark:border-emerald-800 bg-gradient-to-br from-emerald-50/40 to-white dark:from-emerald-950/30 dark:to-slate-900 p-3 sm:p-5 transition-colors duration-300">
                                <div class="mb-3 flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-emerald-600 text-white text-xs font-bold shadow-sm">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </span>
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Our Vision</span>
                                    <span class="rounded-full bg-emerald-100 dark:bg-emerald-900/40 px-2 py-0.5 text-[9px] font-bold text-emerald-700 dark:text-emerald-300 uppercase tracking-wider">Vision</span>
                                </div>
                                <div class="relative">
                                    <textarea
                                        v-model="form.vision"
                                        rows="4"
                                        placeholder="Describe your school's vision — where you want to be..."
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-emerald-100 dark:focus:ring-emerald-900/30"
                                    ></textarea>
                                    <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 dark:text-slate-500 bg-white/70 dark:bg-slate-900/70 px-1.5 py-0.5 rounded">
                                        {{ form.vision?.length || 0 }} chars
                                    </div>
                                </div>
                            </div>

                            <!-- Core Values -->
                            <div class="rounded-2xl border-2 border-pink-100 dark:border-pink-800 bg-gradient-to-br from-pink-50/40 to-white dark:from-pink-950/30 dark:to-slate-900 p-3 sm:p-5 transition-colors duration-300">
                                <div class="mb-3 flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-pink-500 to-pink-600 text-white text-xs font-bold shadow-sm">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                        </svg>
                                    </span>
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Our Values</span>
                                    <span class="rounded-full bg-pink-100 dark:bg-pink-900/40 px-2 py-0.5 text-[9px] font-bold text-pink-700 dark:text-pink-300 uppercase tracking-wider">Values</span>
                                </div>
                                <div class="relative">
                                    <textarea
                                        v-model="form.core_values"
                                        rows="4"
                                        placeholder="List your core values — e.g. Integrity, Excellence, Respect, Community..."
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-pink-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-pink-100 dark:focus:ring-pink-900/30"
                                    ></textarea>
                                    <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 dark:text-slate-500 bg-white/70 dark:bg-slate-900/70 px-1.5 py-0.5 rounded">
                                        {{ form.core_values?.length || 0 }} chars
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 👁️ LIVE PREVIEW -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-50/80 via-white to-slate-50/60 dark:from-slate-800/50 dark:via-slate-900 dark:to-slate-800/40 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-slate-600 to-slate-800 dark:from-slate-500 dark:to-slate-700 shadow-lg shadow-slate-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">👁️ Live Preview</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Real-time preview of your about page</p>
                            </div>
                            <span class="hidden sm:inline-flex items-center gap-1.5 rounded-full bg-emerald-50 dark:bg-emerald-900/40 px-3 py-1.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300 ring-1 ring-emerald-200 dark:ring-emerald-800">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                LIVE
                            </span>
                        </header>

                        <div class="p-3 sm:p-6 space-y-4 sm:space-y-6">
                            <!-- Hero preview -->
                            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-purple-700 shadow-2xl">
                                <img
                                    v-if="imagePreview || existingImage"
                                    :src="imagePreview || existingImage"
                                    alt="About preview"
                                    class="absolute inset-0 h-full w-full object-cover opacity-30"
                                />
                                <div class="absolute inset-0 bg-gradient-to-r from-blue-900/80 via-indigo-900/60 to-transparent"></div>

                                <div class="relative px-5 py-10 sm:px-10 sm:py-14">
                                    <div class="max-w-2xl">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 px-3 py-1 text-[10px] font-bold text-white uppercase tracking-wider mb-3">
                                            <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span>
                                            About Us
                                        </span>

                                        <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-white leading-tight tracking-tight">
                                            {{ form.about_title || 'About Our School' }}
                                        </h1>
                                        <p class="mt-2 text-sm sm:text-base text-blue-100 font-medium">
                                            {{ form.about_subtitle || 'Building a Better Future Through Quality Education' }}
                                        </p>
                                        <p class="mt-3 text-sm sm:text-base text-slate-200 leading-relaxed max-w-xl line-clamp-3">
                                            {{ form.about_description || 'Your about description will appear here...' }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Mission / Vision / Values cards -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="rounded-2xl bg-gradient-to-br from-indigo-50 to-white dark:from-indigo-950/40 dark:to-slate-900 border-2 border-indigo-100 dark:border-indigo-800 p-4 transition-colors duration-300">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-600 text-white shadow-sm">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                            </svg>
                                        </span>
                                        <h4 class="text-sm font-bold text-indigo-900 dark:text-indigo-200">Our Mission</h4>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed line-clamp-3">
                                        {{ form.mission || 'Your mission statement will appear here...' }}
                                    </p>
                                </div>

                                <div class="rounded-2xl bg-gradient-to-br from-emerald-50 to-white dark:from-emerald-950/40 dark:to-slate-900 border-2 border-emerald-100 dark:border-emerald-800 p-4 transition-colors duration-300">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-emerald-600 text-white shadow-sm">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </span>
                                        <h4 class="text-sm font-bold text-emerald-900 dark:text-emerald-200">Our Vision</h4>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed line-clamp-3">
                                        {{ form.vision || 'Your vision statement will appear here...' }}
                                    </p>
                                </div>

                                <div class="rounded-2xl bg-gradient-to-br from-pink-50 to-white dark:from-pink-950/40 dark:to-slate-900 border-2 border-pink-100 dark:border-pink-800 p-4 transition-colors duration-300">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-pink-500 to-pink-600 text-white shadow-sm">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                            </svg>
                                        </span>
                                        <h4 class="text-sm font-bold text-pink-900 dark:text-pink-200">Our Values</h4>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed line-clamp-3">
                                        {{ form.core_values || 'Your core values will appear here...' }}
                                    </p>
                                </div>
                            </div>

                            <!-- History -->
                            <div v-if="form.school_history" class="rounded-2xl bg-gradient-to-br from-amber-50 to-white dark:from-amber-950/40 dark:to-slate-900 border-2 border-amber-100 dark:border-amber-800 p-4 sm:p-6 transition-colors duration-300">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 text-white shadow-sm">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </span>
                                    <h4 class="text-sm sm:text-base font-bold text-amber-900 dark:text-amber-200">Our History</h4>
                                </div>
                                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 leading-relaxed whitespace-pre-line">
                                    {{ form.school_history }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- ACTION BUTTONS -->
                    <!-- ============================================= -->
                    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 rounded-2xl border border-slate-100 dark:border-slate-800 bg-white/95 dark:bg-slate-900/95 p-3 sm:p-4 shadow-xl shadow-slate-200/50 dark:shadow-black/40 backdrop-blur-md transition-colors duration-300">
                        <Link
                            :href="route('website-management.settings')"
                            class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 shadow-sm transition hover:border-slate-300 dark:hover:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 hover:text-slate-800 dark:hover:text-slate-100 text-center"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="group relative inline-flex items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-blue-600 to-cyan-600 px-7 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/30 transition hover:from-blue-700 hover:to-cyan-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <svg v-else class="h-4 w-4 transition group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>