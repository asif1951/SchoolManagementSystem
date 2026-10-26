<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

defineProps({
    canResetPassword: { type: Boolean },
    status:           { type: String },
})

const page = usePage()

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
const schoolName   = computed(() => school.value?.name || 'Childhome Academy')
const schoolMotto  = computed(() => school.value?.motto || 'Knowledge • Character • Future')

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const showPassword = ref(false)

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head :title="`Log in — ${schoolName}`" />

    <div class="login-page relative min-h-screen w-full overflow-hidden flex items-center justify-center bg-slate-50 dark:bg-[#0B1220] transition-colors duration-300">

        <!-- Background hero image — stays visible in BOTH modes -->
        <div
            class="absolute inset-0 bg-cover bg-center"
            :style="{ backgroundImage: `url('${heroImageUrl}')` }"
            :aria-label="heroImageAlt"
        ></div>

        <!-- Light overlay (only in light mode) -->
        <div class="absolute inset-0 bg-gradient-to-br from-[#B9DDF6]/90 via-[#CFE7FA]/75 to-[#E5F2FF]/85 dark:hidden"></div>

        <!-- Dark overlay (only in dark mode) — deep blue, NOT black, keeps image visible -->
        <div class="absolute inset-0 hidden dark:block bg-gradient-to-br from-[#0B1220]/85 via-[#0F1E38]/80 to-[#0B1220]/90"></div>

        <!-- Extra dark tint so card pops but image still bleeds through -->
        <div class="absolute inset-0 hidden dark:block bg-[#0B1220]/30"></div>

        <!-- Decorative dot grids -->
        <div class="pointer-events-none absolute top-8 right-8 opacity-40 dark:opacity-25 z-10 hidden sm:block">
            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                <pattern id="dots1" x="0" y="0" width="12" height="12" patternUnits="userSpaceOnUse">
                    <circle cx="2" cy="2" r="1.4" fill="#7BB0E8" />
                </pattern>
                <rect width="72" height="72" fill="url(#dots1)" />
            </svg>
        </div>
        <div class="pointer-events-none absolute bottom-8 right-8 opacity-40 dark:opacity-25 z-10 hidden sm:block">
            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                <pattern id="dots2" x="0" y="0" width="12" height="12" patternUnits="userSpaceOnUse">
                    <circle cx="2" cy="2" r="1.4" fill="#7BB0E8" />
                </pattern>
                <rect width="72" height="72" fill="url(#dots2)" />
            </svg>
        </div>

        <!-- MAIN CARD -->
        <div
            class="card-enter relative z-20 w-full
                   lg:w-[min(92vw,1280px)]
                   lg:h-[min(88vh,760px)]
                   lg:min-h-[560px]
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
            <!-- LEFT PANEL — Simple Branding                         -->
            <!-- ==================================================== -->
            <div class="relative hidden lg:flex flex-col items-center justify-center overflow-hidden px-10 text-center">

                <!-- Hero image -->
                <div
                    class="absolute inset-0 bg-cover bg-center scale-105"
                    :style="{ backgroundImage: `url('${heroImageUrl}')` }"
                ></div>

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
                        <div class="relative flex h-28 w-28 xl:h-32 xl:w-32 items-center justify-center rounded-full bg-white/95 backdrop-blur-sm shadow-2xl shadow-black/20 dark:shadow-black/50 ring-4 ring-white/30 dark:ring-[#60A5FA]/30 overflow-hidden">
                            <img
                                v-if="logoUrl"
                                :src="logoUrl"
                                :alt="schoolName"
                                class="h-full w-full object-contain p-3"
                            />
                            <svg v-else class="h-14 w-14 text-[#0B4F9E]" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L2 7v2h1v11h18V9h1V7L12 2zm0 2.24L18.76 7H5.24L12 4.24zM5 9h14v9H5V9zm2 2v5h2v-3h2v3h2v-5H7z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Welcome pill -->
                    <span class="fade-up mt-8 inline-flex items-center gap-2 rounded-full bg-white/15 dark:bg-white/10 backdrop-blur-md border border-white/25 dark:border-[#60A5FA]/30 px-4 py-1.5 text-xs xl:text-[13px] font-semibold tracking-widest text-white uppercase" style="animation-delay: 0.25s">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-300 animate-pulse"></span>
                        Welcome Back
                    </span>

                    <!-- School name -->
                    <h1
                        class="fade-up mt-5 max-w-[420px] font-black tracking-tight leading-tight text-white uppercase drop-shadow-[0_4px_16px_rgba(0,0,0,0.25)] dark:drop-shadow-[0_4px_16px_rgba(0,0,0,0.6)]"
                        style="font-size: clamp(1.75rem, 3vw, 2.6rem); animation-delay: 0.4s"
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
            <!-- RIGHT PANEL — Login Form                             -->
            <!-- ==================================================== -->
            <div class="relative flex items-center justify-center bg-white dark:bg-[#0F1E38] px-5 sm:px-8 lg:px-10 xl:px-14 py-8 sm:py-10 lg:py-12 transition-colors duration-500">

                <div class="form-enter w-full max-w-[400px] xl:max-w-[420px]">

                    <!-- Logo -->
                    <div class="flex flex-col items-center text-center mb-6 xl:mb-7">
                        <div class="flex h-14 w-14 xl:h-16 xl:w-16 items-center justify-center rounded-full overflow-hidden dark:bg-white/95 dark:ring-2 dark:ring-[#60A5FA]/25 transition-colors duration-500">
                            <img
                                v-if="logoUrl"
                                :src="logoUrl"
                                :alt="schoolName"
                                class="h-full w-full object-contain dark:p-1.5"
                            />
                            <svg v-else class="h-10 w-10 text-[#0B4F9E]" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L2 7v2h1v11h18V9h1V7L12 2zm0 2.24L18.76 7H5.24L12 4.24zM5 9h14v9H5V9zm2 2v5h2v-3h2v3h2v-5H7z"/>
                            </svg>
                        </div>
                        <p class="mt-3 text-lg xl:text-xl font-black tracking-tight text-[#0B4F9E] dark:text-white leading-none uppercase transition-colors duration-500">
                            {{ schoolName }}
                        </p>
                        <p class="mt-1.5 text-[10px] xl:text-[11px] font-semibold text-[#4A90D9] dark:text-[#93C5FD] tracking-wide transition-colors duration-500">
                            {{ schoolMotto }}
                        </p>
                    </div>

                    <!-- Heading -->
                    <h2 class="text-[22px] xl:text-[26px] font-black text-[#0B4F9E] dark:text-white leading-tight transition-colors duration-500">
                        Login to Your Account
                    </h2>
                    <p class="mt-1.5 text-[13px] xl:text-[14px] text-slate-500 dark:text-slate-400 transition-colors duration-500">
                        Enter your credentials to continue.
                    </p>

                    <!-- Status -->
                    <div
                        v-if="status"
                        class="mt-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800/40 px-4 py-2.5 text-sm font-medium text-emerald-700 dark:text-emerald-300 transition-colors duration-500"
                    >
                        {{ status }}
                    </div>

                    <!-- FORM -->
                    <form @submit.prevent="submit" class="mt-5 xl:mt-6 space-y-3.5 xl:space-y-4">

                        <!-- Email -->
                        <div>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </span>
                                <input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    required
                                    autofocus
                                    autocomplete="username"
                                    placeholder="Username / Email"
                                    class="w-full rounded-xl border border-slate-200 dark:border-[#1E3A8A]/40 bg-white dark:bg-[#0B1220] py-3 xl:py-3.5 pl-11 pr-4 text-[13px] xl:text-[14px] text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-[#0B4F9E] dark:focus:border-[#60A5FA] focus:ring-4 focus:ring-[#0B4F9E]/10 dark:focus:ring-[#60A5FA]/10"
                                />
                            </div>
                            <p v-if="form.errors.email" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">
                                {{ form.errors.email }}
                            </p>
                        </div>

                        <!-- Password -->
                        <div>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </span>
                                <input
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    required
                                    autocomplete="current-password"
                                    placeholder="Password"
                                    class="w-full rounded-xl border border-slate-200 dark:border-[#1E3A8A]/40 bg-white dark:bg-[#0B1220] py-3 xl:py-3.5 pl-11 pr-11 text-[13px] xl:text-[14px] text-slate-700 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 outline-none transition focus:border-[#0B4F9E] dark:focus:border-[#60A5FA] focus:ring-4 focus:ring-[#0B4F9E]/10 dark:focus:ring-[#60A5FA]/10"
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
                            <p v-if="form.errors.password" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">
                                {{ form.errors.password }}
                            </p>
                        </div>

                        <!-- Remember + Forgot -->
                        <div class="flex items-center justify-between pt-1">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    v-model="form.remember"
                                    class="h-4 w-4 rounded border-slate-300 dark:border-slate-600 bg-white dark:bg-[#0B1220] text-[#0B4F9E] dark:text-[#60A5FA] focus:ring-[#0B4F9E] dark:focus:ring-[#60A5FA] focus:ring-offset-0 cursor-pointer"
                                />
                                <span class="text-[12px] xl:text-[13px] font-medium text-slate-600 dark:text-slate-300 transition-colors duration-500">Remember me</span>
                            </label>

                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-[12px] xl:text-[13px] font-semibold text-[#0B4F9E] dark:text-[#60A5FA] hover:text-[#1565C0] dark:hover:text-[#93C5FD] transition"
                            >
                                Forgot Password?
                            </Link>
                        </div>

                        <!-- Login Button -->
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="mt-2 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-[#0B4F9E] hover:bg-[#0A4488] dark:bg-[#1565C0] dark:hover:bg-[#1976D2] px-5 py-3 xl:py-3.5 text-[14px] xl:text-[15px] font-bold text-white shadow-md shadow-[#0B4F9E]/25 dark:shadow-[#1565C0]/40 transition hover:shadow-lg active:scale-[0.99] disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                            <svg v-if="!form.processing" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14M3 12a9 9 0 1118 0 9 9 0 01-18 0z"/>
                            </svg>
                            <svg v-else class="h-5 w-5 animate-spin" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            {{ form.processing ? 'Logging in...' : 'Login' }}
                        </button>

                        <!-- Divider -->
                        <div class="relative py-1.5">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-slate-200 dark:border-[#1E3A8A]/40 transition-colors duration-500"></div>
                            </div>
                            <div class="relative flex justify-center">
                                <span class="bg-white dark:bg-[#0F1E38] px-3 text-[11px] xl:text-[12px] font-semibold text-slate-400 dark:text-slate-500 tracking-wider transition-colors duration-500">OR</span>
                            </div>
                        </div>

                        <!-- Create New Account -->
                        <Link
                            v-if="route().has('register')"
                            :href="route('register')"
                            class="w-full inline-flex items-center justify-center gap-2 rounded-xl border-2 border-[#0B4F9E]/40 dark:border-[#60A5FA]/40 bg-white dark:bg-transparent px-5 py-2.5 xl:py-3 text-[13px] xl:text-[14px] font-bold text-[#0B4F9E] dark:text-[#60A5FA] transition hover:border-[#0B4F9E] dark:hover:border-[#60A5FA] hover:bg-[#0B4F9E]/5 dark:hover:bg-[#60A5FA]/10 active:scale-[0.99]"
                        >
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            Create New Account
                        </Link>

                        <!-- Back to Home -->
                        <div class="pt-1.5 text-center">
                            <Link
                                :href="route('home')"
                                class="inline-flex items-center gap-1.5 text-[12px] xl:text-[13px] font-semibold text-slate-500 dark:text-slate-400 hover:text-[#0B4F9E] dark:hover:text-[#60A5FA] transition"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                                </svg>
                                Back to Home
                            </Link>
                        </div>

                    </form>
                </div>
            </div>

        </div>
    </div>
</template>

<style scoped>
/* Dark mode: native controls (checkbox, scrollbar) dark hoye jabe */
:global(.dark) .login-page {
    color-scheme: dark;
}

/* Dark mode: browser autofill er white/yellow background fix */
:global(.dark) .login-page input:-webkit-autofill,
:global(.dark) .login-page input:-webkit-autofill:hover,
:global(.dark) .login-page input:-webkit-autofill:focus {
    -webkit-text-fill-color: #f1f5f9;
    caret-color: #f1f5f9;
    -webkit-box-shadow: 0 0 0 1000px #0B1220 inset;
    box-shadow: 0 0 0 1000px #0B1220 inset;
    transition: background-color 9999s ease-in-out 0s;
}

/* Light mode: autofill er background input er moto white rakhe */
.login-page input:-webkit-autofill,
.login-page input:-webkit-autofill:hover,
.login-page input:-webkit-autofill:focus {
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