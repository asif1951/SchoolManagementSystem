<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const page = usePage()

// ✅ School name + motto now come from backend (website_settings)
const school = computed(() => page.props.school ?? {})
const hero   = computed(() => page.props.hero ?? {})

const storageUrl = (path) => {
    if (!path) return null
    if (path.startsWith('http')) return path
    return `/storage/${path.replace(/^\/+/, '')}`
}

const logoUrl      = computed(() => storageUrl(school.value?.logo))
const heroImageUrl = computed(() =>
    storageUrl(hero.value?.image) ||
    'https://images.unsplash.com/photo-1580582932707-520aed937b7b?w=1600&q=80'
)
const heroImageAlt = computed(() => hero.value?.image_alt || school.value?.name || 'School')

// ✅ Pull real values from backend
const schoolName  = computed(() => school.value?.name  || 'Childhome Academy')
const schoolMotto = computed(() => school.value?.motto || 'Knowledge • Character • Future')

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
})

const showPassword        = ref(false)
const showConfirmPassword = ref(false)

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head :title="`Register — ${schoolName}`" />

    <!-- PAGE WRAPPER -->
    <div class="register-page relative min-h-screen w-full overflow-hidden flex items-center justify-center p-0 sm:p-4 lg:p-6 transition-colors duration-500">

        <!-- Background hero image -->
        <div
            class="absolute inset-0 bg-cover bg-center"
            :style="{ backgroundImage: `url('${heroImageUrl}')` }"
            :aria-label="heroImageAlt"
        ></div>

        <!-- Light overlay -->
        <div class="absolute inset-0 bg-gradient-to-br from-[#B9DDF6]/85 via-[#CFE7FA]/75 to-[#E5F2FF]/80 dark:hidden"></div>

        <!-- Dark overlay -->
        <div class="absolute inset-0 hidden dark:block bg-gradient-to-br from-[#0B1220]/85 via-[#0F1E38]/80 to-[#0B1220]/90"></div>
        <div class="absolute inset-0 hidden dark:block bg-[#0B1220]/25"></div>

        <!-- Decorative dot grids -->
        <div class="pointer-events-none absolute top-8 right-8 opacity-40 dark:opacity-20 z-10 hidden sm:block">
            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                <pattern id="dots1" x="0" y="0" width="12" height="12" patternUnits="userSpaceOnUse">
                    <circle cx="2" cy="2" r="1.4" fill="#7BB0E8" />
                </pattern>
                <rect width="72" height="72" fill="url(#dots1)" />
            </svg>
        </div>
        <div class="pointer-events-none absolute bottom-8 right-8 opacity-40 dark:opacity-20 z-10 hidden sm:block">
            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                <pattern id="dots2" x="0" y="0" width="12" height="12" patternUnits="userSpaceOnUse">
                    <circle cx="2" cy="2" r="1.4" fill="#7BB0E8" />
                </pattern>
                <rect width="72" height="72" fill="url(#dots2)" />
            </svg>
        </div>

        <!-- ==================================================== -->
        <!-- MAIN CARD — Slightly shorter height                   -->
        <!-- ==================================================== -->
        <div
            class="card-enter relative z-20 w-full
                   lg:w-[min(94vw,1280px)]
                   lg:min-h-[470px]
                   bg-white dark:bg-[#0F1E38]
                   rounded-none sm:rounded-2xl lg:rounded-3xl
                   shadow-[0_30px_80px_-20px_rgba(11,79,158,0.45)]
                   dark:shadow-[0_30px_80px_-20px_rgba(0,0,0,0.85)]
                   ring-1 ring-transparent dark:ring-[#1E3A8A]/30
                   overflow-hidden
                   grid grid-cols-1 lg:grid-cols-2
                   transition-colors duration-500"
        >

            <!-- ==================================================== -->
            <!-- LEFT — Simple Branding                                -->
            <!-- ==================================================== -->
            <div class="relative hidden lg:flex flex-col items-center justify-center overflow-hidden px-10 text-center">

                <!-- Hero image -->
                <img
                    :src="heroImageUrl"
                    :alt="heroImageAlt"
                    class="absolute inset-0 h-full w-full object-cover scale-105"
                />

                <!-- Blue gradient overlay (light mode) -->
                <div class="absolute inset-0 bg-gradient-to-br from-[#0B4F9E]/90 via-[#1565C0]/80 to-[#1883DB]/75 dark:hidden"></div>

                <!-- Navy gradient overlay (dark mode) — image halka dekha jabe -->
                <div class="absolute inset-0 hidden dark:block bg-gradient-to-br from-[#0B1220]/90 via-[#0F1E38]/82 to-[#1E3A8A]/65"></div>

                <!-- Floating soft glow shapes -->
                <div class="blob blob-1 pointer-events-none absolute -top-16 -left-16 h-64 w-64 rounded-full bg-white/15 dark:bg-[#60A5FA]/15 blur-3xl"></div>
                <div class="blob blob-2 pointer-events-none absolute -bottom-20 -right-12 h-72 w-72 rounded-full bg-[#93C5FD]/25 dark:bg-[#1883DB]/20 blur-3xl"></div>
                <div class="blob blob-3 pointer-events-none absolute top-1/2 right-10 h-24 w-24 rounded-full bg-white/10 dark:bg-[#60A5FA]/10 blur-2xl"></div>

                <!-- Subtle ring decorations -->
                <div class="pointer-events-none absolute -top-24 -right-24 h-72 w-72 rounded-full border border-white/15 dark:border-[#60A5FA]/15"></div>
                <div class="pointer-events-none absolute -bottom-28 -left-28 h-80 w-80 rounded-full border border-white/10 dark:border-[#60A5FA]/10"></div>

                <!-- CONTENT -->
                <div class="relative z-10 flex flex-col items-center">

                    <!-- Logo with pulse ring -->
                    <div class="fade-up relative" style="animation-delay: 0.1s">
                        <span class="pulse-ring absolute inset-0 rounded-full bg-white/30 dark:bg-[#60A5FA]/30"></span>
                        <div class="relative flex h-24 w-24 xl:h-28 xl:w-28 items-center justify-center rounded-full bg-white/95 backdrop-blur-sm shadow-2xl shadow-black/20 dark:shadow-black/50 ring-4 ring-white/30 dark:ring-[#60A5FA]/30 overflow-hidden">
                            <img
                                v-if="logoUrl"
                                :src="logoUrl"
                                :alt="schoolName"
                                class="h-full w-full object-contain p-3"
                            />
                            <svg v-else class="h-12 w-12 text-[#0B4F9E]" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L2 7v2h1v11h18V9h1V7L12 2zm0 2.24L18.76 7H5.24L12 4.24zM5 9h14v9H5V9zm2 2v5h2v-3h2v3h2v-5H7z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Join pill -->
                    <span class="fade-up mt-7 inline-flex items-center gap-2 rounded-full bg-white/15 dark:bg-white/10 backdrop-blur-md border border-white/25 dark:border-[#60A5FA]/30 px-4 py-1.5 text-xs xl:text-[13px] font-semibold tracking-widest text-white uppercase" style="animation-delay: 0.25s">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-300 animate-pulse"></span>
                        Join Our School Family
                    </span>

                    <!-- School name -->
                    <h1
                        class="fade-up mt-5 max-w-[420px] font-black tracking-tight leading-tight text-white uppercase drop-shadow-[0_4px_16px_rgba(0,0,0,0.25)] dark:drop-shadow-[0_4px_16px_rgba(0,0,0,0.6)]"
                        style="font-size: clamp(1.6rem, 2.8vw, 2.4rem); animation-delay: 0.4s"
                    >
                        {{ schoolName }}
                    </h1>

                    <!-- Divider -->
                    <div class="fade-up mt-5 h-1 w-14 rounded-full bg-gradient-to-r from-transparent via-white dark:via-[#93C5FD] to-transparent" style="animation-delay: 0.55s"></div>

                    <!-- Motto -->
                    <p class="fade-up mt-4 text-sm xl:text-base font-medium tracking-wide text-white/85 dark:text-[#BFDBFE]" style="animation-delay: 0.7s">
                        {{ schoolMotto }}
                    </p>
                </div>
            </div>

            <!-- ==================================================== -->
            <!-- RIGHT — Register form                                 -->
            <!-- ==================================================== -->
            <div class="relative flex items-center justify-center bg-white dark:bg-[#0F1E38] px-5 sm:px-8 lg:px-10 xl:px-12 py-5 sm:py-6 lg:py-8 transition-colors duration-500">

                <div class="form-enter w-full max-w-[440px]">

                    <!-- Logo + School name from backend -->
                    <div class="flex flex-col items-center text-center mb-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-full overflow-hidden dark:bg-white/95 dark:ring-2 dark:ring-[#60A5FA]/25 transition-colors duration-500">
                            <img
                                v-if="logoUrl"
                                :src="logoUrl"
                                :alt="schoolName"
                                class="h-full w-full object-contain dark:p-1.5"
                            />
                            <svg v-else class="h-9 w-9 text-[#0B4F9E]" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L2 7v2h1v11h18V9h1V7L12 2zm0 2.24L18.76 7H5.24L12 4.24zM5 9h14v9H5V9zm2 2v5h2v-3h2v3h2v-5H7z"/>
                            </svg>
                        </div>
                        <p class="mt-2 text-base xl:text-lg font-black tracking-tight text-[#0B4F9E] dark:text-white leading-none uppercase">
                            {{ schoolName }}
                        </p>
                        <p class="mt-1 text-[10px] xl:text-[11px] font-semibold text-[#4A90D9] dark:text-[#93C5FD] tracking-wide">
                            {{ schoolMotto }}
                        </p>
                    </div>

                    <!-- Heading -->
                    <h2 class="text-[22px] xl:text-[26px] font-black text-[#0B4F9E] dark:text-white leading-tight">
                        Register Now
                    </h2>
                    <p class="mt-1 text-[12px] xl:text-[13px] text-slate-500 dark:text-slate-400">
                        Fill in the details below to create your account.
                    </p>

                    <!-- FORM -->
                    <form @submit.prevent="submit" class="mt-4 space-y-2.5">

                        <!-- Name -->
                        <div>
                            <label for="name" class="mb-1 inline-flex items-center gap-1.5 text-[12px] font-bold text-[#0B4F9E] dark:text-slate-200">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                Name
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </span>
                                <input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    required
                                    autofocus
                                    autocomplete="name"
                                    placeholder="Enter your full name"
                                    class="w-full rounded-xl border border-slate-200 dark:border-[#1E3A8A]/40 bg-white dark:bg-[#0B1220] py-2.5 pl-10 pr-4 text-[13px] text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-[#0B4F9E] dark:focus:border-[#60A5FA] focus:ring-4 focus:ring-[#0B4F9E]/10 dark:focus:ring-[#60A5FA]/10"
                                />
                            </div>
                            <p v-if="form.errors.name" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="mb-1 inline-flex items-center gap-1.5 text-[12px] font-bold text-[#0B4F9E] dark:text-slate-200">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                Email
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </span>
                                <input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    required
                                    autocomplete="username"
                                    placeholder="Enter your email address"
                                    class="w-full rounded-xl border border-slate-200 dark:border-[#1E3A8A]/40 bg-white dark:bg-[#0B1220] py-2.5 pl-10 pr-4 text-[13px] text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-[#0B4F9E] dark:focus:border-[#60A5FA] focus:ring-4 focus:ring-[#0B4F9E]/10 dark:focus:ring-[#60A5FA]/10"
                                />
                            </div>
                            <p v-if="form.errors.email" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                                {{ form.errors.email }}
                            </p>
                        </div>

                        <!-- Password -->
                        <div>
                            <label for="password" class="mb-1 inline-flex items-center gap-1.5 text-[12px] font-bold text-[#0B4F9E] dark:text-slate-200">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                                Password
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </span>
                                <input
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    required
                                    autocomplete="new-password"
                                    placeholder="Enter your password"
                                    class="w-full rounded-xl border border-slate-200 dark:border-[#1E3A8A]/40 bg-white dark:bg-[#0B1220] py-2.5 pl-10 pr-11 text-[13px] text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-[#0B4F9E] dark:focus:border-[#60A5FA] focus:ring-4 focus:ring-[#0B4F9E]/10 dark:focus:ring-[#60A5FA]/10"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition"
                                >
                                    <svg v-if="showPassword" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 012.927-4.568M6.18 6.18A9.97 9.97 0 0112 5c4.478 0 8.268 2.943 9.542 7a9.97 9.97 0 01-4.293 5.068M15 12a3 3 0 11-6 0 3 3 0 016 0zM3 3l18 18"/>
                                    </svg>
                                </button>
                            </div>
                            <p v-if="form.errors.password" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                                {{ form.errors.password }}
                            </p>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label for="password_confirmation" class="mb-1 inline-flex items-center gap-1.5 text-[12px] font-bold text-[#0B4F9E] dark:text-slate-200">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                </svg>
                                Confirm Password
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </span>
                                <input
                                    id="password_confirmation"
                                    v-model="form.password_confirmation"
                                    :type="showConfirmPassword ? 'text' : 'password'"
                                    required
                                    autocomplete="new-password"
                                    placeholder="Confirm your password"
                                    class="w-full rounded-xl border border-slate-200 dark:border-[#1E3A8A]/40 bg-white dark:bg-[#0B1220] py-2.5 pl-10 pr-11 text-[13px] text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-[#0B4F9E] dark:focus:border-[#60A5FA] focus:ring-4 focus:ring-[#0B4F9E]/10 dark:focus:ring-[#60A5FA]/10"
                                />
                                <button
                                    type="button"
                                    @click="showConfirmPassword = !showConfirmPassword"
                                    class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition"
                                >
                                    <svg v-if="showConfirmPassword" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 012.927-4.568M6.18 6.18A9.97 9.97 0 0112 5c4.478 0 8.268 2.943 9.542 7a9.97 9.97 0 01-4.293 5.068M15 12a3 3 0 11-6 0 3 3 0 016 0zM3 3l18 18"/>
                                    </svg>
                                </button>
                            </div>
                            <p v-if="form.errors.password_confirmation" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                                {{ form.errors.password_confirmation }}
                            </p>
                        </div>

                        <!-- Register Button -->
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="mt-1.5 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-[#0B4F9E] hover:bg-[#0A4488] dark:bg-[#1565C0] dark:hover:bg-[#1976D2] px-5 py-3 text-[14px] font-bold text-white shadow-md shadow-[#0B4F9E]/25 dark:shadow-[#1565C0]/40 transition hover:shadow-lg active:scale-[0.99] disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                            <svg v-if="!form.processing" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            <svg v-else class="h-5 w-5 animate-spin" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            {{ form.processing ? 'Registering...' : 'Register' }}
                        </button>

                        <!-- Divider -->
                        <div class="relative py-1">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-slate-200 dark:border-[#1E3A8A]/40"></div>
                            </div>
                            <div class="relative flex justify-center">
                                <span class="bg-white dark:bg-[#0F1E38] px-3 text-[11px] font-semibold text-slate-400 dark:text-slate-500 tracking-wide">
                                    Already registered?
                                </span>
                            </div>
                        </div>

                        <!-- Login link -->
                        <div class="text-center">
                            <Link
                                :href="route('login')"
                                class="inline-flex items-center gap-1.5 text-[13px] font-bold text-[#0B4F9E] dark:text-[#60A5FA] hover:text-[#1565C0] dark:hover:text-[#93C5FD] transition"
                            >
                                Login
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                </svg>
                            </Link>
                        </div>

                    </form>
                </div>
            </div>

        </div>
    </div>
</template>

<style scoped>
/* Dark mode: native controls (scrollbar etc.) dark hoye jabe */
:global(.dark) .register-page {
    color-scheme: dark;
}

/* Dark mode: browser autofill er white/yellow background fix */
:global(.dark) .register-page input:-webkit-autofill,
:global(.dark) .register-page input:-webkit-autofill:hover,
:global(.dark) .register-page input:-webkit-autofill:focus {
    -webkit-text-fill-color: #f1f5f9;
    caret-color: #f1f5f9;
    -webkit-box-shadow: 0 0 0 1000px #0B1220 inset;
    box-shadow: 0 0 0 1000px #0B1220 inset;
    transition: background-color 9999s ease-in-out 0s;
}

/* Light mode: autofill er text color thik rakhe */
.register-page input:-webkit-autofill,
.register-page input:-webkit-autofill:hover,
.register-page input:-webkit-autofill:focus {
    -webkit-text-fill-color: #334155;
    transition: background-color 9999s ease-in-out 0s;
}

/* Card entrance */
.card-enter {
    animation: cardIn 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes cardIn {
    from {
        opacity: 0;
        transform: translateY(24px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Left panel content fade-up (staggered via inline animation-delay) */
.fade-up {
    opacity: 0;
    animation: fadeUp 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Right form soft fade-in */
.form-enter {
    animation: formIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) 0.2s both;
}

@keyframes formIn {
    from {
        opacity: 0;
        transform: translateX(16px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

/* Logo pulse ring */
.pulse-ring {
    animation: pulseRing 2.8s ease-out infinite;
}

@keyframes pulseRing {
    0% {
        transform: scale(1);
        opacity: 0.6;
    }
    100% {
        transform: scale(1.5);
        opacity: 0;
    }
}

/* Floating glow blobs */
.blob-1 {
    animation: floatA 9s ease-in-out infinite;
}
.blob-2 {
    animation: floatB 11s ease-in-out infinite;
}
.blob-3 {
    animation: floatA 7s ease-in-out infinite reverse;
}

@keyframes floatA {
    0%, 100% { transform: translate(0, 0); }
    50%      { transform: translate(24px, 18px); }
}

@keyframes floatB {
    0%, 100% { transform: translate(0, 0); }
    50%      { transform: translate(-28px, -20px); }
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
    .card-enter,
    .fade-up,
    .form-enter,
    .pulse-ring,
    .blob-1,
    .blob-2,
    .blob-3 {
        animation: none !important;
        opacity: 1;
    }
}
</style>