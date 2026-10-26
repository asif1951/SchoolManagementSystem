<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps({
    hero: { type: Object, default: () => ({}) },
    availablePages: { type: Array, default: () => [] },
})

const page = usePage()

// ============================================================
// ⭐ SECONDARY BUTTON — Fixed page options
// ============================================================
const SECONDARY_PAGES = [
    { key: 'about',    label: 'About' },
    { key: 'result',   label: 'Result' },
    { key: 'contact',  label: 'Contact' },
    { key: 'teachers', label: 'Teachers' },
]

// ============================================================
// ORIGINAL — from DB
// ============================================================
const original = {
    hero_title:     props.hero?.hero_title ?? '',
    hero_subtitle:  props.hero?.hero_subtitle ?? '',
    hero_image_alt: props.hero?.hero_image_alt ?? '',
    button1_show:   props.hero?.button1_show ?? true,
    button1_text:   props.hero?.button1_text ?? '',
    button1_type:   props.hero?.button1_type ?? 'internal',
    button1_page:   props.hero?.button1_page ?? '',
    button1_url:    props.hero?.button1_url ?? '',
    button2_show:   props.hero?.button2_show ?? true,
    button2_text:   props.hero?.button2_text ?? '',
    button2_type:   props.hero?.button2_type ?? 'internal',
    button2_page:   props.hero?.button2_page ?? '',
    button2_url:    props.hero?.button2_url ?? '',
}

// ============================================================
// FORM
// ============================================================
const form = useForm({
    hero_title:     original.hero_title,
    hero_subtitle:  original.hero_subtitle,
    hero_image:     null,
    hero_image_alt: original.hero_image_alt,

    button1_show:   original.button1_show,
    button1_text:   original.button1_text,
    button1_type:   original.button1_type,
    button1_page:   original.button1_page,
    button1_url:    original.button1_url,

    button2_show:   original.button2_show,
    button2_text:   original.button2_text,
    button2_type:   original.button2_type,
    button2_page:   original.button2_page,
    button2_url:    original.button2_url,
})

// ============================================================
// EXISTING IMAGE — from DB
// ============================================================
const existingImage = computed(() => {
    const path = props.hero?.hero_image
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
    form.hero_image = file
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
    form.hero_image = null
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
// FIELD LABELS + WATCHERS
// ============================================================
const fieldLabels = {
    hero_title:     'Hero Title',
    hero_subtitle:  'Hero Subtitle',
    hero_image_alt: 'Image Alt Text',
    button1_text:   'Button 1 Text',
    button1_type:   'Button 1 Type',
    button1_page:   'Button 1 Page',
    button1_url:    'Button 1 URL',
    button2_text:   'Button 2 Text',
    button2_type:   'Button 2 Type',
    button2_page:   'Button 2 Page',
    button2_url:    'Button 2 URL',
}

const watchedFields = [
    'hero_title', 'hero_subtitle', 'hero_image_alt',
    'button1_show', 'button1_text', 'button1_type', 'button1_page', 'button1_url',
    'button2_show', 'button2_text', 'button2_type', 'button2_page', 'button2_url',
]

watchedFields.forEach((field) => {
    watch(() => form[field], (newVal, oldVal) => {
        if (newVal === oldVal) return
        const label = fieldLabels[field] ?? field
        const origVal = original[field] ?? ''

        if (typeof newVal === 'boolean') {
            showToast(`${label} ${newVal ? 'enabled' : 'disabled'}`, 'info')
            return
        }

        if (String(newVal).trim() === String(origVal).trim()) {
            showToast(`${label} reverted to original value`, 'info')
            return
        }
        showToast(`${label} updated`, 'info')
    })
})

watch(() => form.hero_image, (newFile) => {
    if (newFile) showToast('Hero image selected — click Save to apply', 'info')
})

watch(() => form.hero_title, (newVal) => {
    if (!form.hero_image_alt && newVal) form.hero_image_alt = newVal
})

// ============================================================
// PREVIEW BUTTONS
// ============================================================
const previewButton1 = computed(() => {
    if (!form.button1_show || !form.button1_text) return null
    return { text: form.button1_text }
})

const previewButton2 = computed(() => {
    if (!form.button2_show || !form.button2_text) return null
    return { text: form.button2_text }
})

// ============================================================
// SUBMIT
// ============================================================
const submit = () => {
    form.post(route('website-management.home-page.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: (pageResp) => {
            showToast(
                pageResp.props.flash?.success || 'Home page saved successfully! ✅',
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
    <Head title="Home Page Management" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 px-3 py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 transition-colors duration-300">
            <!-- Decorative blobs - overflow-hidden wrapper এর ভিতরে রাখা হয়েছে -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -left-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-indigo-200/30 dark:bg-indigo-800/10 blur-3xl"></div>
                <div class="absolute top-1/3 -right-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-purple-200/30 dark:bg-purple-800/10 blur-3xl"></div>
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

                    <!-- HERO CONTENT -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-50/80 via-white to-purple-50/60 dark:from-indigo-950/30 dark:via-slate-900 dark:to-purple-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 shadow-lg shadow-indigo-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🦸 Hero Content</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Main banner shown at the top of your homepage</p>
                            </div>
                        </header>

                        <div class="grid grid-cols-1 gap-4 sm:gap-6 p-3 sm:p-6">
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Hero Title <span class="text-rose-500">*</span>
                                </label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-indigo-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.hero_title"
                                        type="text"
                                        placeholder="e.g. Welcome to Childhome Academy"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-indigo-100 dark:focus:ring-indigo-900/30"
                                    />
                                </div>
                                <p v-if="form.errors.hero_title" class="mt-1.5 text-xs font-medium text-rose-500 dark:text-rose-400">
                                    {{ form.errors.hero_title }}
                                </p>
                            </div>

                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Hero Subtitle</label>
                                <div class="relative">
                                    <textarea
                                        v-model="form.hero_subtitle"
                                        rows="3"
                                        placeholder="Quality education, strong values and a bright future for every child."
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-indigo-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-indigo-100 dark:focus:ring-indigo-900/30"
                                    ></textarea>
                                    <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 dark:text-slate-500 bg-white/70 dark:bg-slate-900/70 px-1.5 py-0.5 rounded">
                                        {{ form.hero_subtitle?.length || 0 }} chars
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- HERO IMAGE -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-50/80 via-white to-teal-50/60 dark:from-emerald-950/30 dark:via-slate-900 dark:to-teal-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🖼️ Hero Image</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Background image for the hero section</p>
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
                                            :alt="form.hero_image_alt || 'Hero image'"
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
                                                    {{ form.hero_image ? 'New image selected' : 'Current hero image' }}
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
                                                {{ isDragging ? 'Drop your image here!' : 'Upload your hero image' }}
                                            </p>
                                            <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 mt-1">Drag & drop or click to browse</p>
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

                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Image Alt Text
                                    <span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/40 px-2 py-0.5 rounded-full">SEO</span>
                                </label>
                                <input
                                    v-model="form.hero_image_alt"
                                    type="text"
                                    placeholder="e.g. Childhome Academy School Campus"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-emerald-100 dark:focus:ring-emerald-900/30"
                                />
                            </div>
                        </div>
                    </section>

                    <!-- CTA BUTTONS -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-purple-50/80 via-white to-pink-50/60 dark:from-purple-950/30 dark:via-slate-900 dark:to-pink-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-purple-500 to-pink-600 shadow-lg shadow-purple-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🔘 Call-to-Action Buttons</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Two buttons shown below the hero text</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6 space-y-4 sm:space-y-6">
                            <!-- BUTTON 1 -->
                            <div class="rounded-2xl border-2 border-purple-100 dark:border-purple-800 bg-gradient-to-br from-purple-50/40 to-white dark:from-purple-950/30 dark:to-slate-900 p-3 sm:p-5 transition-colors duration-300">
                                <div class="mb-4 flex flex-wrap items-center gap-3">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-purple-500 to-purple-600 text-white text-xs font-bold shadow-sm">1</span>
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Primary Button</span>
                                    <label class="ml-auto flex items-center gap-2 cursor-pointer select-none">
                                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">
                                            {{ form.button1_show ? 'Visible' : 'Hidden' }}
                                        </span>
                                        <span
                                            @click="form.button1_show = !form.button1_show"
                                            :class="[
                                                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-300',
                                                form.button1_show ? 'bg-gradient-to-r from-purple-500 to-purple-600' : 'bg-slate-300 dark:bg-slate-700'
                                            ]"
                                        >
                                            <span
                                                :class="[
                                                    'inline-block h-4 w-4 transform rounded-full bg-white shadow-md transition-transform duration-300',
                                                    form.button1_show ? 'translate-x-6' : 'translate-x-1'
                                                ]"
                                            ></span>
                                        </span>
                                    </label>
                                </div>

                                <div :class="['grid grid-cols-1 gap-4 sm:gap-5 sm:grid-cols-2 transition-opacity', !form.button1_show && 'opacity-50 pointer-events-none']">
                                    <div>
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Button Text</label>
                                        <input
                                            v-model="form.button1_text"
                                            type="text"
                                            placeholder="e.g. Check Result"
                                            class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                                        />
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Link Type</label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <button
                                                type="button"
                                                @click="form.button1_type = 'internal'"
                                                :class="[
                                                    'flex items-center justify-center gap-1.5 rounded-xl border-2 px-3 py-2.5 text-xs font-semibold transition-all',
                                                    form.button1_type === 'internal'
                                                        ? 'border-purple-500 bg-purple-50 dark:bg-purple-950/50 text-purple-700 dark:text-purple-300'
                                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600'
                                                ]"
                                            >
                                                Internal
                                            </button>
                                            <button
                                                type="button"
                                                @click="form.button1_type = 'external'"
                                                :class="[
                                                    'flex items-center justify-center gap-1.5 rounded-xl border-2 px-3 py-2.5 text-xs font-semibold transition-all',
                                                    form.button1_type === 'external'
                                                        ? 'border-purple-500 bg-purple-50 dark:bg-purple-950/50 text-purple-700 dark:text-purple-300'
                                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600'
                                                ]"
                                            >
                                                External
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="form.button1_type === 'internal'" class="sm:col-span-2">
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Select Page</label>
                                        <select
                                            v-model="form.button1_page"
                                            class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30 cursor-pointer"
                                        >
                                            <option value="">-- Choose a page --</option>
                                            <option v-for="p in availablePages" :key="p.key" :value="p.key">{{ p.label }}</option>
                                        </select>
                                    </div>

                                    <div v-else class="sm:col-span-2">
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">External URL</label>
                                        <input
                                            v-model="form.button1_url"
                                            type="text"
                                            placeholder="https://example.com/page"
                                            class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- BUTTON 2 -->
                            <div class="rounded-2xl border-2 border-pink-100 dark:border-pink-800 bg-gradient-to-br from-pink-50/40 to-white dark:from-pink-950/30 dark:to-slate-900 p-3 sm:p-5 transition-colors duration-300">
                                <div class="mb-4 flex flex-wrap items-center gap-3">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-pink-500 to-pink-600 text-white text-xs font-bold shadow-sm">2</span>
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Secondary Button</span>
                                    <label class="ml-auto flex items-center gap-2 cursor-pointer select-none">
                                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">
                                            {{ form.button2_show ? 'Visible' : 'Hidden' }}
                                        </span>
                                        <span
                                            @click="form.button2_show = !form.button2_show"
                                            :class="[
                                                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-300',
                                                form.button2_show ? 'bg-gradient-to-r from-pink-500 to-pink-600' : 'bg-slate-300 dark:bg-slate-700'
                                            ]"
                                        >
                                            <span
                                                :class="[
                                                    'inline-block h-4 w-4 transform rounded-full bg-white shadow-md transition-transform duration-300',
                                                    form.button2_show ? 'translate-x-6' : 'translate-x-1'
                                                ]"
                                            ></span>
                                        </span>
                                    </label>
                                </div>

                                <div :class="['grid grid-cols-1 gap-4 sm:gap-5 sm:grid-cols-2 transition-opacity', !form.button2_show && 'opacity-50 pointer-events-none']">
                                    <div>
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Button Text</label>
                                        <input
                                            v-model="form.button2_text"
                                            type="text"
                                            placeholder="e.g. Learn More"
                                            class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-pink-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-pink-100 dark:focus:ring-pink-900/30"
                                        />
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Link Type</label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <button
                                                type="button"
                                                @click="form.button2_type = 'internal'"
                                                :class="[
                                                    'flex items-center justify-center gap-1.5 rounded-xl border-2 px-3 py-2.5 text-xs font-semibold transition-all',
                                                    form.button2_type === 'internal'
                                                        ? 'border-pink-500 bg-pink-50 dark:bg-pink-950/50 text-pink-700 dark:text-pink-300'
                                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600'
                                                ]"
                                            >
                                                Internal
                                            </button>
                                            <button
                                                type="button"
                                                @click="form.button2_type = 'external'"
                                                :class="[
                                                    'flex items-center justify-center gap-1.5 rounded-xl border-2 px-3 py-2.5 text-xs font-semibold transition-all',
                                                    form.button2_type === 'external'
                                                        ? 'border-pink-500 bg-pink-50 dark:bg-pink-950/50 text-pink-700 dark:text-pink-300'
                                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600'
                                                ]"
                                            >
                                                External
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="form.button2_type === 'internal'" class="sm:col-span-2">
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Select Page</label>
                                        <select
                                            v-model="form.button2_page"
                                            class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition focus:border-pink-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-pink-100 dark:focus:ring-pink-900/30 cursor-pointer"
                                        >
                                            <option value="">-- Choose a page --</option>
                                            <option v-for="p in SECONDARY_PAGES" :key="p.key" :value="p.key">{{ p.label }}</option>
                                        </select>
                                    </div>

                                    <div v-else class="sm:col-span-2">
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">External URL</label>
                                        <input
                                            v-model="form.button2_url"
                                            type="text"
                                            placeholder="https://example.com/page"
                                            class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-pink-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-pink-100 dark:focus:ring-pink-900/30"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- LIVE PREVIEW -->
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
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Real-time preview of your hero section</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6">
                            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-900 to-slate-900 shadow-2xl">
                                <img
                                    v-if="imagePreview || existingImage"
                                    :src="imagePreview || existingImage"
                                    :alt="form.hero_image_alt || 'Hero image'"
                                    class="absolute inset-0 h-full w-full object-cover opacity-40"
                                />
                                <div class="absolute inset-0 bg-gradient-to-r from-slate-900/85 via-slate-900/60 to-transparent"></div>

                                <div class="relative px-5 py-10 sm:px-10 sm:py-16 lg:px-14 lg:py-20">
                                    <div class="max-w-2xl">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-500/20 backdrop-blur-sm border border-indigo-400/30 px-3 py-1 text-[10px] font-bold text-indigo-200 uppercase tracking-wider mb-3">
                                            <span class="h-1.5 w-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
                                            Welcome
                                        </span>

                                        <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-white leading-tight tracking-tight">
                                            {{ form.hero_title || 'Welcome to Childhome Academy' }}
                                        </h1>
                                        <p class="mt-3 sm:mt-4 text-sm sm:text-base lg:text-lg text-slate-200 leading-relaxed max-w-xl">
                                            {{ form.hero_subtitle || 'Quality education, strong values and a bright future for every child.' }}
                                        </p>

                                        <div v-if="previewButton1 || previewButton2" class="mt-5 sm:mt-6 flex flex-wrap gap-2 sm:gap-3">
                                            <button
                                                v-if="previewButton1"
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-lg shadow-indigo-500/40"
                                            >
                                                {{ previewButton1.text }}
                                                <svg class="h-3.5 w-3.5 sm:h-4 sm:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                                </svg>
                                            </button>

                                            <button
                                                v-if="previewButton2"
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-xl border-2 border-white/40 bg-white/10 px-5 py-2.5 text-xs sm:text-sm font-bold text-white backdrop-blur-sm"
                                            >
                                                {{ previewButton2.text }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ACTION BUTTONS -->
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
                            class="group relative inline-flex items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 px-7 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:from-indigo-700 hover:to-purple-700 disabled:cursor-not-allowed disabled:opacity-60"
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