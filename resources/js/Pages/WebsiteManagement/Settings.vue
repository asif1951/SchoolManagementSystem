<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const page = usePage();

// Keep original values for comparison
const original = {
    school_name:       props.settings?.school_name ?? '',
    school_motto:      props.settings?.school_motto ?? '',
    established_year:  props.settings?.established_year ?? '',
    short_description: props.settings?.short_description ?? '',
    phone:             props.settings?.phone ?? '',
    alternative_phone: props.settings?.alternative_phone ?? '',
    email:             props.settings?.email ?? '',
    address:           props.settings?.address ?? '',
    office_hours:      props.settings?.office_hours ?? '',
    google_maps_url:   props.settings?.google_maps_url ?? '',
    facebook_url:      props.settings?.facebook_url ?? '',
    youtube_url:       props.settings?.youtube_url ?? '',
    instagram_url:     props.settings?.instagram_url ?? '',
    linkedin_url:      props.settings?.linkedin_url ?? '',
    footer_description: props.settings?.footer_description ?? '',
    footer_copyright:  props.settings?.footer_copyright ?? '',
};

const form = useForm({
    // School Information
    school_name:        original.school_name,
    school_motto:       original.school_motto,
    established_year:   original.established_year,
    short_description:  original.short_description,
    school_logo:        null,
    favicon:            null,

    // Contact Details
    phone:              original.phone,
    alternative_phone:  original.alternative_phone,
    email:              original.email,
    address:            original.address,
    office_hours:       original.office_hours,
    google_maps_url:    original.google_maps_url,

    // Social Links
    facebook_url:       original.facebook_url,
    youtube_url:        original.youtube_url,
    instagram_url:      original.instagram_url,
    linkedin_url:       original.linkedin_url,

    // Footer
    footer_description: original.footer_description,
    footer_copyright:   original.footer_copyright,
});

// Human-readable field labels for toast
const fieldLabels = {
    school_name:        'School Name',
    school_motto:       'School Motto',
    established_year:   'Established Year',
    short_description:  'Short Description',
    school_logo:        'School Logo',
    favicon:            'Favicon',
    phone:              'Phone',
    alternative_phone:  'Alternative Phone',
    email:              'Email',
    address:            'Address',
    office_hours:       'Office Hours',
    google_maps_url:    'Google Maps URL',
    facebook_url:       'Facebook URL',
    youtube_url:        'YouTube URL',
    instagram_url:      'Instagram URL',
    linkedin_url:       'LinkedIn URL',
    footer_description: 'Footer Description',
    footer_copyright:   'Footer Copyright',
};

// Existing image previews (from DB)
const existingLogo = computed(() =>
    props.settings?.school_logo ? `/storage/${props.settings.school_logo}` : null
);
const existingFavicon = computed(() =>
    props.settings?.favicon ? `/storage/${props.settings.favicon}` : null
);

const logoPreview = ref(null);
const faviconPreview = ref(null);

// ============================================================
// ✅ INLINE TOAST
// ============================================================
const toast = ref({ show: false, message: '', type: 'success' });
let toastTimeout = null;

const showToast = (message, type = 'success') => {
    if (toastTimeout) clearTimeout(toastTimeout);
    toast.value = { show: true, message, type };
    toastTimeout = setTimeout(() => (toast.value.show = false), 3500);
};

// Show flash messages on page load
if (page.props.flash?.success) showToast(page.props.flash.success, 'success');
if (page.props.flash?.error) showToast(page.props.flash.error, 'error');

// ============================================================
// ✅ WATCH FOR FIELD CHANGES → SHOW TOAST
// ============================================================
const watchedFields = [
    'school_name', 'school_motto', 'established_year', 'short_description',
    'phone', 'alternative_phone', 'email', 'address', 'office_hours', 'google_maps_url',
    'facebook_url', 'youtube_url', 'instagram_url', 'linkedin_url',
    'footer_description', 'footer_copyright',
];

watchedFields.forEach((field) => {
    watch(
        () => form[field],
        (newVal, oldVal) => {
            // Skip initial change (if any)
            if (newVal === oldVal) return;

            const label = fieldLabels[field] ?? field;
            const origVal = original[field] ?? '';

            // If value matches original → user reverted
            if (String(newVal).trim() === String(origVal).trim()) {
                showToast(`${label} reverted to original value`, 'info');
                return;
            }

            // Otherwise → user edited
            showToast(`${label} updated`, 'info');
        }
    );
});

// Watch file inputs (logo / favicon)
watch(() => form.school_logo, (newFile) => {
    if (newFile) showToast('School Logo selected — click Save to apply', 'info');
});

watch(() => form.favicon, (newFile) => {
    if (newFile) showToast('Favicon selected — click Save to apply', 'info');
});

// ============================================================
// FILE HANDLERS
// ============================================================
const onLogoChange = (e) => {
    const file = e.target.files[0];
    form.school_logo = file;
    if (file) logoPreview.value = URL.createObjectURL(file);
};

const onFaviconChange = (e) => {
    const file = e.target.files[0];
    form.favicon = file;
    if (file) faviconPreview.value = URL.createObjectURL(file);
};

// ============================================================
// SUBMIT
// ============================================================
const submit = () => {
    form.post(route('website-management.settings.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showToast('Website settings saved successfully! ✅', 'success');
        },
        onError: () => {
            showToast('Please fix the errors and try again.', 'error');
        },
    });
};
</script>

<template>
    <Head title="Website Settings" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-blue-50/40 px-3 py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950">
            <!-- Decorative blobs - overflow-hidden wrapper এর ভিতরে রাখা হয়েছে -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -left-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-blue-200/30 blur-3xl dark:bg-blue-800/20"></div>
                <div class="absolute top-1/3 -right-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-indigo-200/30 blur-3xl dark:bg-indigo-800/20"></div>
            </div>

            <div class="relative w-full">

                <!-- ============================================= -->
                <!-- ✅ INLINE TOAST -->
                <!-- ============================================= -->
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
                            toast.type === 'success' ? 'bg-emerald-50/90 border-emerald-300 text-emerald-800 shadow-emerald-200/50 dark:bg-emerald-950/80 dark:border-emerald-700 dark:text-emerald-200 dark:shadow-emerald-900/30' : '',
                            toast.type === 'error'   ? 'bg-rose-50/90 border-rose-300 text-rose-800 shadow-rose-200/50 dark:bg-rose-950/80 dark:border-rose-700 dark:text-rose-200 dark:shadow-rose-900/30' : '',
                            toast.type === 'info'    ? 'bg-blue-50/90 border-blue-300 text-blue-800 shadow-blue-200/50 dark:bg-blue-950/80 dark:border-blue-700 dark:text-blue-200 dark:shadow-blue-900/30' : '',
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
                            :class="[
                                'flex-shrink-0 rounded-lg p-1 transition',
                                toast.type === 'success' ? 'text-emerald-500 hover:bg-emerald-100 hover:text-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-900/40 dark:hover:text-emerald-200' : '',
                                toast.type === 'error'   ? 'text-rose-500 hover:bg-rose-100 hover:text-rose-700 dark:text-rose-400 dark:hover:bg-rose-900/40 dark:hover:text-rose-200' : '',
                                toast.type === 'info'    ? 'text-blue-500 hover:bg-blue-100 hover:text-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/40 dark:hover:text-blue-200' : '',
                            ]"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </Transition>

                <form @submit.prevent="submit" class="space-y-4 sm:space-y-6">

                    <!-- ============================================= -->
                    <!-- 🏫 SCHOOL INFORMATION -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 shadow-xl shadow-slate-200/50 ring-1 ring-slate-100 backdrop-blur-sm dark:bg-slate-900/80 dark:shadow-slate-950/50 dark:ring-slate-800">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 bg-gradient-to-r from-blue-50/80 via-white to-indigo-50/60 px-3 py-3 sm:px-6 sm:py-5 dark:border-slate-800 dark:from-blue-950/40 dark:via-slate-900 dark:to-indigo-950/40">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 shadow-lg shadow-blue-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 truncate dark:text-slate-100">🏫 School Information</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 truncate dark:text-slate-400">Basic identity of your institution</p>
                            </div>
                        </header>

                        <div class="grid grid-cols-1 gap-4 sm:gap-6 p-3 sm:p-6 lg:grid-cols-2">
                            <!-- School Name -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    School Name <span class="text-rose-500">*</span>
                                </label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-blue-500 dark:text-slate-500 dark:group-focus-within:text-blue-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.school_name"
                                        type="text"
                                        placeholder="e.g. Childhome Academy"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-800 dark:focus:ring-blue-900/40"
                                    />
                                </div>
                                <p v-if="form.errors.school_name" class="mt-1.5 text-xs font-medium text-rose-500">
                                    {{ form.errors.school_name }}
                                </p>
                            </div>

                            <!-- Established Year -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Established Year
                                </label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-blue-500 dark:text-slate-500 dark:group-focus-within:text-blue-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.established_year"
                                        type="number"
                                        min="1800"
                                        max="2100"
                                        placeholder="e.g. 2009"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-800 dark:focus:ring-blue-900/40"
                                    />
                                </div>
                            </div>

                            <!-- School Motto -->
                            <div class="lg:col-span-2">
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    School Motto / Tagline
                                </label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-blue-500 dark:text-slate-500 dark:group-focus-within:text-blue-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.school_motto"
                                        type="text"
                                        placeholder="e.g. Knowledge • Character • Future"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-800 dark:focus:ring-blue-900/40"
                                    />
                                </div>
                            </div>

                            <!-- Short Description -->
                            <div class="lg:col-span-2">
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Short Description
                                </label>
                                <textarea
                                    v-model="form.short_description"
                                    rows="3"
                                    placeholder="Childhome Academy is committed to providing quality education..."
                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-800 dark:focus:ring-blue-900/40"
                                ></textarea>
                                <p class="mt-1.5 text-[11px] sm:text-xs text-slate-400 dark:text-slate-500">
                                    This short description will appear on the homepage and about section.
                                </p>
                            </div>

                            <!-- School Logo -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    School Logo
                                </label>
                                <label class="group flex cursor-pointer items-center gap-3 sm:gap-4 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/50 p-3 sm:p-4 transition hover:border-blue-400 hover:bg-blue-50/40 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-blue-600 dark:hover:bg-blue-950/20">
                                    <div class="flex h-14 w-14 sm:h-16 sm:w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-md ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                                        <img v-if="logoPreview" :src="logoPreview" class="h-full w-full object-cover" />
                                        <img v-else-if="existingLogo" :src="existingLogo" class="h-full w-full object-cover" />
                                        <svg v-else class="h-6 w-6 sm:h-7 sm:w-7 text-slate-400 group-hover:text-blue-500 dark:text-slate-500 dark:group-hover:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs sm:text-sm font-semibold text-slate-700 group-hover:text-blue-600 truncate dark:text-slate-200 dark:group-hover:text-blue-400">
                                            {{ logoPreview ? 'Change logo' : (existingLogo ? 'Replace logo' : 'Upload logo') }}
                                        </p>
                                        <p class="text-[10px] sm:text-xs text-slate-400 truncate dark:text-slate-500">PNG, JPG, WEBP or SVG · Max 2MB</p>
                                    </div>
                                    <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" @change="onLogoChange" />
                                </label>
                            </div>

                            <!-- Favicon -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Favicon
                                </label>
                                <label class="group flex cursor-pointer items-center gap-3 sm:gap-4 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/50 p-3 sm:p-4 transition hover:border-indigo-400 hover:bg-indigo-50/40 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-indigo-600 dark:hover:bg-indigo-950/20">
                                    <div class="flex h-14 w-14 sm:h-16 sm:w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-md ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                                        <img v-if="faviconPreview" :src="faviconPreview" class="h-full w-full object-cover" />
                                        <img v-else-if="existingFavicon" :src="existingFavicon" class="h-full w-full object-cover" />
                                        <svg v-else class="h-6 w-6 sm:h-7 sm:w-7 text-slate-400 group-hover:text-indigo-500 dark:text-slate-500 dark:group-hover:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.05 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs sm:text-sm font-semibold text-slate-700 group-hover:text-indigo-600 truncate dark:text-slate-200 dark:group-hover:text-indigo-400">
                                            {{ faviconPreview ? 'Change favicon' : (existingFavicon ? 'Replace favicon' : 'Upload favicon') }}
                                        </p>
                                        <p class="text-[10px] sm:text-xs text-slate-400 truncate dark:text-slate-500">32×32 or 64×64 · PNG, SVG · Max 1MB</p>
                                    </div>
                                    <input type="file" accept="image/png,image/svg+xml,image/x-icon" class="hidden" @change="onFaviconChange" />
                                </label>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 📞 CONTACT DETAILS -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 shadow-xl shadow-slate-200/50 ring-1 ring-slate-100 backdrop-blur-sm dark:bg-slate-900/80 dark:shadow-slate-950/50 dark:ring-slate-800">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 bg-gradient-to-r from-emerald-50/80 via-white to-teal-50/60 px-3 py-3 sm:px-6 sm:py-5 dark:border-slate-800 dark:from-emerald-950/40 dark:via-slate-900 dark:to-teal-950/40">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 truncate dark:text-slate-100">📞 Contact Details</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 truncate dark:text-slate-400">How visitors can reach you</p>
                            </div>
                        </header>

                        <div class="grid grid-cols-1 gap-4 sm:gap-6 p-3 sm:p-6 md:grid-cols-2">
                            <!-- Phone -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Phone</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-emerald-500 dark:text-slate-500 dark:group-focus-within:text-emerald-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.phone"
                                        type="text"
                                        placeholder="+880 1XXX-XXXXXX"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-emerald-500 dark:focus:bg-slate-800 dark:focus:ring-emerald-900/40"
                                    />
                                </div>
                                <p v-if="form.errors.phone" class="mt-1.5 text-xs font-medium text-rose-500">
                                    {{ form.errors.phone }}
                                </p>
                            </div>

                            <!-- Alternative Phone -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Alternative Phone</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-emerald-500 dark:text-slate-500 dark:group-focus-within:text-emerald-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.alternative_phone"
                                        type="text"
                                        placeholder="+880 1XXX-XXXXXX"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-emerald-500 dark:focus:bg-slate-800 dark:focus:ring-emerald-900/40"
                                    />
                                </div>
                            </div>

                            <!-- Email -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Email</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-emerald-500 dark:text-slate-500 dark:group-focus-within:text-emerald-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.email"
                                        type="email"
                                        placeholder="info@school.com"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-emerald-500 dark:focus:bg-slate-800 dark:focus:ring-emerald-900/40"
                                    />
                                </div>
                                <p v-if="form.errors.email" class="mt-1.5 text-xs font-medium text-rose-500">
                                    {{ form.errors.email }}
                                </p>
                            </div>

                            <!-- Office Hours -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Office Hours</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-emerald-500 dark:text-slate-500 dark:group-focus-within:text-emerald-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.office_hours"
                                        type="text"
                                        placeholder="e.g. Sat - Thu, 8:00 AM - 4:00 PM"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-emerald-500 dark:focus:bg-slate-800 dark:focus:ring-emerald-900/40"
                                    />
                                </div>
                            </div>

                            <!-- Address -->
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Address</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute left-0 top-3.5 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-emerald-500 dark:text-slate-500 dark:group-focus-within:text-emerald-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </div>
                                    <textarea
                                        v-model="form.address"
                                        rows="3"
                                        placeholder="Enter full school address"
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-emerald-500 dark:focus:bg-slate-800 dark:focus:ring-emerald-900/40"
                                    ></textarea>
                                </div>
                                <p v-if="form.errors.address" class="mt-1.5 text-xs font-medium text-rose-500">
                                    {{ form.errors.address }}
                                </p>
                            </div>

                            <!-- Google Maps URL -->
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Google Maps URL / Embed URL
                                </label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition group-focus-within:text-emerald-500 dark:text-slate-500 dark:group-focus-within:text-emerald-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.google_maps_url"
                                        type="text"
                                        placeholder="https://maps.google.com/..."
                                        class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-emerald-500 dark:focus:bg-slate-800 dark:focus:ring-emerald-900/40"
                                    />
                                </div>
                                <p class="mt-1.5 text-[11px] sm:text-xs text-slate-400 dark:text-slate-500">
                                    Paste the Google Maps embed URL to show a map on the contact page.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 🌐 SOCIAL LINKS -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 shadow-xl shadow-slate-200/50 ring-1 ring-slate-100 backdrop-blur-sm dark:bg-slate-900/80 dark:shadow-slate-950/50 dark:ring-slate-800">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 bg-gradient-to-r from-pink-50/80 via-white to-purple-50/60 px-3 py-3 sm:px-6 sm:py-5 dark:border-slate-800 dark:from-pink-950/40 dark:via-slate-900 dark:to-purple-950/40">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-pink-500 to-purple-600 shadow-lg shadow-pink-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 truncate dark:text-slate-100">🌐 Social Links</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 truncate dark:text-slate-400">Connect your social media profiles</p>
                            </div>
                        </header>

                        <div class="grid grid-cols-1 gap-4 sm:gap-6 p-3 sm:p-6 md:grid-cols-2">
                            <!-- Facebook -->
                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-md bg-blue-600 text-white flex-shrink-0">
                                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.9h2.54V9.85c0-2.52 1.49-3.9 3.78-3.9 1.09 0 2.24.19 2.24.19v2.47h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.9h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z" />
                                        </svg>
                                    </span>
                                    Facebook
                                </label>
                                <input
                                    v-model="form.facebook_url"
                                    type="text"
                                    placeholder="https://facebook.com/yourschool"
                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-800 dark:focus:ring-blue-900/40"
                                />
                            </div>

                            <!-- YouTube -->
                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-md bg-red-600 text-white flex-shrink-0">
                                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M23.5 6.2a3 3 0 00-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 00.5 6.2 31.3 31.3 0 000 12a31.3 31.3 0 00.5 5.8 3 3 0 002.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 002.1-2.1A31.3 31.3 0 0024 12a31.3 31.3 0 00-.5-5.8zM9.6 15.6V8.4l6.2 3.6-6.2 3.6z" />
                                        </svg>
                                    </span>
                                    YouTube
                                </label>
                                <input
                                    v-model="form.youtube_url"
                                    type="text"
                                    placeholder="https://youtube.com/@yourschool"
                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-red-500 focus:bg-white focus:ring-4 focus:ring-red-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-red-500 dark:focus:bg-slate-800 dark:focus:ring-red-900/40"
                                />
                            </div>

                            <!-- Instagram -->
                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-md bg-gradient-to-br from-pink-500 to-purple-600 text-white flex-shrink-0">
                                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23a3.7 3.7 0 01-.9 1.38 3.7 3.7 0 01-1.38.9c-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 01-1.38-.9 3.7 3.7 0 01-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 1.62c-3.15 0-3.52.01-4.76.07-1.15.05-1.77.24-2.18.4-.55.22-.94.47-1.35.88-.41.41-.66.8-.88 1.35-.16.41-.35 1.03-.4 2.18-.06 1.24-.07 1.61-.07 4.76s.01 3.52.07 4.76c.05 1.15.24 1.77.4 2.18.22.55.47.94.88 1.35.41.41.8.66 1.35.88.41.16 1.03.35 2.18.4 1.24.06 1.61.07 4.76.07s3.52-.01 4.76-.07c1.15-.05 1.77-.24 2.18-.4.55-.22.94-.47 1.35-.88.41-.41.66-.8.88-1.35.16-.41.35-1.03.4-2.18.06-1.24.07-1.61.07-4.76s-.01-3.52-.07-4.76c-.05-1.15-.24-1.77-.4-2.18a3.6 3.6 0 00-.88-1.35 3.6 3.6 0 00-1.35-.88c-.41-.16-1.03-.35-2.18-.4-1.24-.06-1.61-.07-4.76-.07zm0 2.76a5.46 5.46 0 110 10.92 5.46 5.46 0 010-10.92zm0 9a3.54 3.54 0 100-7.08 3.54 3.54 0 000 7.08zm6.95-9.22a1.27 1.27 0 11-2.55 0 1.27 1.27 0 012.55 0z" />
                                        </svg>
                                    </span>
                                    Instagram
                                </label>
                                <input
                                    v-model="form.instagram_url"
                                    type="text"
                                    placeholder="https://instagram.com/yourschool"
                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-pink-500 focus:bg-white focus:ring-4 focus:ring-pink-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-pink-500 dark:focus:bg-slate-800 dark:focus:ring-pink-900/40"
                                />
                            </div>

                            <!-- LinkedIn -->
                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-md bg-sky-700 text-white flex-shrink-0">
                                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
                                        </svg>
                                    </span>
                                    LinkedIn
                                </label>
                                <input
                                    v-model="form.linkedin_url"
                                    type="text"
                                    placeholder="https://linkedin.com/company/yourschool"
                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-4 focus:ring-sky-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-sky-500 dark:focus:bg-slate-800 dark:focus:ring-sky-900/40"
                                />
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 🦶 FOOTER -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 shadow-xl shadow-slate-200/50 ring-1 ring-slate-100 backdrop-blur-sm dark:bg-slate-900/80 dark:shadow-slate-950/50 dark:ring-slate-800">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 bg-gradient-to-r from-amber-50/80 via-white to-orange-50/60 px-3 py-3 sm:px-6 sm:py-5 dark:border-slate-800 dark:from-amber-950/40 dark:via-slate-900 dark:to-orange-950/40">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 shadow-lg shadow-amber-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M4 6h16M4 12h16M4 18h7" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 truncate dark:text-slate-100">🦶 Footer</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 truncate dark:text-slate-400">Text shown at the bottom of the website</p>
                            </div>
                        </header>

                        <div class="space-y-4 sm:space-y-6 p-3 sm:p-6">
                            <!-- Footer Description -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Footer Description
                                </label>
                                <textarea
                                    v-model="form.footer_description"
                                    rows="3"
                                    placeholder="A short description shown in the footer about your school..."
                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40"
                                ></textarea>
                                <p class="mt-1.5 text-[11px] sm:text-xs text-slate-400 dark:text-slate-500">
                                    This text will appear in the website footer, below the logo.
                                </p>
                            </div>

                            <!-- Footer Copyright -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Footer Copyright Text
                                </label>
                                <input
                                    v-model="form.footer_copyright"
                                    type="text"
                                    placeholder="© 2025 Your School. All rights reserved."
                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40"
                                />
                                <p class="mt-2 text-[11px] sm:text-xs text-slate-400 dark:text-slate-500">
                                    This text will appear at the very bottom of your website.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- ACTION BUTTONS -->
                    <!-- ============================================= -->
                    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 rounded-2xl border border-slate-100 bg-white/95 p-3 sm:p-4 shadow-xl shadow-slate-200/50 backdrop-blur-md dark:border-slate-800 dark:bg-slate-900/95 dark:shadow-slate-950/50">
                        <button
                            type="button"
                            class="rounded-xl border border-slate-200 bg-white px-6 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-100"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="group relative inline-flex items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-7 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/30 transition hover:from-blue-700 hover:to-indigo-700 hover:shadow-blue-500/50 focus:outline-none focus:ring-4 focus:ring-blue-200 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-blue-900/50"
                        >
                            <svg
                                v-if="form.processing"
                                class="h-4 w-4 animate-spin"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>

                            <svg
                                v-else
                                class="h-4 w-4 transition group-hover:scale-110"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7" />
                            </svg>

                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>