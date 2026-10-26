<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import PublicNavbar from '@/Components/PublicNavbar.vue'
import PublicFooter from '@/Components/PublicFooter.vue'
import { useTheme } from '@/composables/useTheme'

const props = defineProps({
    canLogin:    { type: Boolean, default: false },
    canRegister: { type: Boolean, default: false },
    contact:     { type: Object,  default: null },
    hero:        { type: Object,  default: null },
})

const page = usePage()
const school = computed(() => page.props.school ?? {})
const { initTheme } = useTheme()

// ============================================================
// BRAND COLORS
// ============================================================
const BRAND_PRIMARY = '#0055B6'
const BRAND_DARK    = '#0B376B'
const BRAND_ACCENT  = '#F5B82E'

const brandVars = computed(() => ({
    '--brand':       school.value?.primary_color   || BRAND_PRIMARY,
    '--brand-dark':  school.value?.secondary_color || BRAND_DARK,
    '--accent':      school.value?.accent_color    || BRAND_ACCENT,
    '--brand-mid':   '#1883DB',
    '--brand-light': '#60A5FA',
    '--brand-tint':  '#EBF4FF',
}))

// ============================================================
// HERO IMAGE — from backend
// ============================================================
const heroImage = computed(() =>
    props.hero?.image ? `/storage/${props.hero.image}` : null
)

// ============================================================
// CONTACT HELPERS
// ============================================================
const contactInfo = computed(() => props.contact ?? page.props.contact ?? {})

const whatsappLink = (phone) => {
    if (!phone) return null
    let digits = String(phone).replace(/[^\d]/g, '')
    if (!digits) return null
    if (digits.startsWith('00')) digits = digits.slice(2)
    return `https://wa.me/${digits}`
}

const telLink = (phone) => {
    if (!phone) return null
    return `tel:${String(phone).replace(/\s+/g, '')}`
}

// ============================================================
// FOOTER DATA
// ============================================================
const year = new Date().getFullYear()

const quickLinks = [
    { name: 'Home',     href: '/' },
    { name: 'About',    href: '/about' },
    { name: 'Result',   href: '/result' },
    { name: 'Teachers', href: '/teachers' },
    { name: 'Contact',  href: '/contact' },
]

const scrollTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })

// ============================================================
// FORM STATE
// ============================================================
const form = ref({
    name:    '',
    email:   '',
    phone:   '',
    subject: '',
    message: '',
})

const sending  = ref(false)
const sent     = ref(false)
const errorMsg = ref('')

const subjectOptions = [
    'General Inquiry',
    'Admission',
    'Academic / Result',
    'Fees & Payment',
    'Complaint',
    'Other',
]

const submit = () => {
    errorMsg.value = ''

    if (!form.value.name.trim() || !form.value.email.trim() || !form.value.message.trim()) {
        errorMsg.value = 'Please fill in your name, email and message.'
        return
    }

    sending.value = true

    setTimeout(() => {
        sending.value = false
        sent.value = true
        form.value = { name: '', email: '', phone: '', subject: '', message: '' }
    }, 900)
}

// ============================================================
// SCROLL REVEAL
// ============================================================
let revealObserver = null
const REVEAL_CLASSES = ['reveal', 'reveal-left', 'reveal-right', 'reveal-zoom', 'is-visible']

const vReveal = {
    mounted(el, binding) {
        const v = binding.value
        const delay = typeof v === 'number' ? v : (v?.delay ?? 0)
        const type  = typeof v === 'object' && v ? v.type : null

        el.classList.add('reveal')
        if (type) el.classList.add(`reveal-${type}`)
        if (delay) el.style.transitionDelay = `${delay}ms`
        el.dataset.revealDelay = String(delay)

        if (!('IntersectionObserver' in window)) {
            REVEAL_CLASSES.forEach(c => el.classList.remove(c))
            el.style.transitionDelay = ''
            return
        }
        if (!revealObserver) {
            revealObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return
                    const target = entry.target
                    target.classList.add('is-visible')
                    revealObserver.unobserve(target)

                    const wait = 900 + Number(target.dataset.revealDelay || 0)
                    setTimeout(() => {
                        REVEAL_CLASSES.forEach(c => target.classList.remove(c))
                        target.style.transitionDelay = ''
                    }, wait)
                })
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' })
        }
        revealObserver.observe(el)
    },
}

onMounted(() => {
    initTheme()
})

onBeforeUnmount(() => {
    revealObserver?.disconnect()
    revealObserver = null
})
</script>

<template>
    <Head :title="`Contact — ${school?.name ?? 'School'}`" />

    <div
        :style="brandVars"
        class="school-page min-h-screen bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-100 transition-colors duration-300"
    >
        <PublicNavbar />

        <!-- ============================================================ -->
        <!-- HERO BANNER — Image from backend -->
        <!-- ============================================================ -->
        <section class="relative overflow-hidden">
            <div class="absolute inset-0">
                <!-- ✅ Backend hero image -->
                <img
                    v-if="heroImage"
                    :src="heroImage"
                    :alt="hero?.image_alt || 'Contact banner'"
                    class="h-full w-full object-cover"
                />
                <!-- Fallback: gradient background if no backend image -->
                <div v-else class="h-full w-full bg-gradient-to-br from-[#0B376B] via-[#0055B6] to-[#1883DB]"></div>

                <!-- Overlay -->
                <div class="absolute inset-0 bg-gradient-to-r from-[#0055B6]/95 via-[#1883DB]/80 to-[#60A5FA]/40"></div>
            </div>

            <svg class="absolute bottom-0 left-0 w-full h-8 sm:h-14" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,80 H1440 V30 C1200,70 960,10 720,40 C480,70 240,10 0,50 Z" fill="#ffffff" class="fill-white dark:fill-slate-950" />
            </svg>

            <div class="relative mx-auto max-w-[1200px] px-4 sm:px-6 lg:px-8 pt-10 sm:pt-14 pb-20 sm:pb-28">
                <nav class="flex items-center gap-2 text-xs sm:text-sm font-medium text-white/80 mb-4">
                    <Link href="/" class="hover:text-white transition">Home</Link>
                    <svg class="h-3 w-3 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                    <span class="text-white font-semibold">Contact</span>
                </nav>

                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white leading-tight">
                    Contact Us
                </h1>
                <p class="mt-3 max-w-xl text-sm sm:text-base text-white/90 leading-relaxed">
                    We are always here to help. Feel free to reach out<br class="hidden sm:block" />
                    for any queries, suggestions or support.
                </p>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- MAIN CONTENT -->
        <!-- ============================================================ -->
        <section class="relative px-4 sm:px-6 lg:px-8 -mt-10 sm:-mt-14 pb-16 sm:pb-20">
            <div class="mx-auto max-w-[1200px]">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8">

                    <!-- LEFT: Contact Information -->
                    <div v-reveal="{ type: 'left' }" class="rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-slate-100 dark:ring-slate-800 shadow-xl shadow-slate-200/60 dark:shadow-black/40 p-6 sm:p-8">

                        <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.18em] text-[#1883DB] dark:text-[#60A5FA]">
                            <span class="h-px w-6 bg-[#1883DB] dark:bg-[#60A5FA]"></span>
                            Get in Touch
                        </p>

                        <h2 class="mt-3 text-2xl sm:text-3xl font-extrabold text-[#0B376B] dark:text-slate-100 leading-tight">
                            Our Contact Information
                        </h2>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                            You can reach us through the following contact details<br class="hidden sm:block" />
                            or visit our school during office hours.
                        </p>

                        <div class="mt-7 grid grid-cols-1 sm:grid-cols-2 gap-4">

                            <!-- Address -->
                            <div class="flex items-start gap-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 p-4 ring-1 ring-slate-100 dark:ring-slate-700/60">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#DCEBFF] dark:bg-[#0055B6]/20 text-[#0055B6] dark:text-[#60A5FA]">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#0B376B] dark:text-slate-100">Our Address</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed break-words">
                                        {{ contactInfo.address || 'Address will appear here once added.' }}
                                    </p>
                                </div>
                            </div>

                            <!-- Phone -->
                            <div class="flex items-start gap-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 p-4 ring-1 ring-slate-100 dark:ring-slate-700/60">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#DCEBFF] dark:bg-[#0055B6]/20 text-[#0055B6] dark:text-[#60A5FA]">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#0B376B] dark:text-slate-100">Phone</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 break-words">
                                        {{ contactInfo.phone || '—' }}
                                    </p>
                                    <p v-if="contactInfo.alternative_phone" class="text-xs text-slate-500 dark:text-slate-400 break-words">
                                        {{ contactInfo.alternative_phone }}
                                    </p>
                                </div>
                            </div>

                            <!-- Email -->
                            <div class="flex items-start gap-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 p-4 ring-1 ring-slate-100 dark:ring-slate-700/60">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#DCEBFF] dark:bg-[#0055B6]/20 text-[#0055B6] dark:text-[#60A5FA]">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#0B376B] dark:text-slate-100">Email</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 break-all">
                                        {{ contactInfo.email || '—' }}
                                    </p>
                                    <p v-if="contactInfo.alternative_email" class="text-xs text-slate-500 dark:text-slate-400 break-all">
                                        {{ contactInfo.alternative_email }}
                                    </p>
                                </div>
                            </div>

                            <!-- Office Hours -->
                            <div class="flex items-start gap-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 p-4 ring-1 ring-slate-100 dark:ring-slate-700/60">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#DCEBFF] dark:bg-[#0055B6]/20 text-[#0055B6] dark:text-[#60A5FA]">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#0B376B] dark:text-slate-100">Office Hours</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed whitespace-pre-line break-words">
                                        {{ contactInfo.office_hours || 'Saturday - Thursday\n9:00 AM - 3:00 PM' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- MAP -->
                        <div class="mt-6 overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-700">
                            <a
                                v-if="contactInfo.google_maps_url"
                                :href="contactInfo.google_maps_url"
                                target="_blank"
                                rel="noopener"
                                class="block group relative h-56 sm:h-64 bg-slate-100 dark:bg-slate-800"
                            >
                                <iframe
                                    v-if="contactInfo.google_maps_url.includes('embed') || contactInfo.google_maps_url.includes('/maps/embed')"
                                    :src="contactInfo.google_maps_url"
                                    class="absolute inset-0 h-full w-full"
                                    style="border:0;"
                                    loading="lazy"
                                    referrerpolicy="no-referrer-when-downgrade"
                                    title="School location on Google Maps"
                                ></iframe>

                                <div v-else class="absolute inset-0 flex flex-col items-center justify-center text-center p-6 bg-gradient-to-br from-[#EBF4FF] to-[#DCEBFF] dark:from-slate-800 dark:to-slate-900">
                                    <div class="absolute inset-0 opacity-40" style="background-image: radial-gradient(circle, #94A3B8 1px, transparent 1px); background-size: 24px 24px;"></div>
                                    <div class="relative flex flex-col items-center">
                                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[#0055B6] text-white shadow-lg ring-4 ring-white/60 dark:ring-slate-700/60 transition group-hover:scale-110">
                                            <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                        </span>
                                        <p class="mt-3 text-sm font-bold text-[#0B376B] dark:text-slate-100">
                                            {{ school?.name || 'School' }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 max-w-xs">
                                            {{ contactInfo.address }}
                                        </p>
                                        <span class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-[#0055B6] dark:text-[#60A5FA] group-hover:underline">
                                            View larger map
                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                            </svg>
                                        </span>
                                    </div>
                                </div>
                            </a>
                            <div v-else class="h-56 sm:h-64 flex flex-col items-center justify-center text-center p-6 bg-slate-50 dark:bg-slate-800">
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-200 dark:bg-slate-700 text-slate-400">
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                    </svg>
                                </span>
                                <p class="mt-3 text-sm font-semibold text-slate-500 dark:text-slate-400">Map will appear here once added.</p>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Contact Form -->
                    <div v-reveal="{ type: 'right' }" class="rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-slate-100 dark:ring-slate-800 shadow-xl shadow-slate-200/60 dark:shadow-black/40 p-6 sm:p-8">

                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-[#DCEBFF] dark:bg-[#0055B6]/20 text-[#0055B6] dark:text-[#60A5FA]">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                </svg>
                            </span>
                            <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-[#1883DB] dark:text-[#60A5FA]">
                                Send Us a Message
                            </p>
                        </div>

                        <h2 class="mt-3 text-2xl sm:text-3xl font-extrabold text-[#0B376B] dark:text-slate-100 leading-tight">
                            Get In Touch
                        </h2>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                            Have a question or need assistance? Fill out the form below<br class="hidden sm:block" />
                            and we'll get back to you as soon as possible.
                        </p>

                        <Transition name="fade">
                            <div v-if="sent" class="mt-5 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 p-4 flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-500 text-white">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                                </span>
                                <div>
                                    <p class="text-sm font-bold text-green-800 dark:text-green-300">Message sent successfully!</p>
                                    <p class="mt-0.5 text-xs text-green-700 dark:text-green-400">We'll get back to you within 24 hours.</p>
                                </div>
                            </div>
                        </Transition>

                        <Transition name="fade">
                            <div v-if="errorMsg" class="mt-5 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4">
                                <p class="text-sm font-semibold text-red-700 dark:text-red-300">{{ errorMsg }}</p>
                            </div>
                        </Transition>

                        <form class="mt-5 space-y-4" @submit.prevent="submit">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#0055B6] dark:text-[#60A5FA]">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </span>
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        placeholder="Your Name *"
                                        class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 pl-11 pr-4 py-3 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition focus:border-[#0055B6] focus:ring-2 focus:ring-[#0055B6]/20"
                                    />
                                </div>

                                <div class="relative">
                                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#0055B6] dark:text-[#60A5FA]">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                    </span>
                                    <input
                                        v-model="form.email"
                                        type="email"
                                        placeholder="Your Email *"
                                        class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 pl-11 pr-4 py-3 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition focus:border-[#0055B6] focus:ring-2 focus:ring-[#0055B6]/20"
                                    />
                                </div>
                            </div>

                            <div class="relative">
                                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#0055B6] dark:text-[#60A5FA]">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h10M7 12h10M7 17h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" />
                                    </svg>
                                </span>
                                <select
                                    v-model="form.subject"
                                    class="w-full appearance-none rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 pl-11 pr-10 py-3 text-sm text-slate-800 dark:text-slate-100 outline-none transition focus:border-[#0055B6] focus:ring-2 focus:ring-[#0055B6]/20"
                                    :class="!form.subject ? 'text-slate-400 dark:text-slate-500' : ''"
                                >
                                    <option value="" disabled>Subject</option>
                                    <option v-for="opt in subjectOptions" :key="opt" :value="opt">{{ opt }}</option>
                                </select>
                                <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-400">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </span>
                            </div>

                            <div class="relative">
                                <span class="pointer-events-none absolute left-4 top-4 text-[#0055B6] dark:text-[#60A5FA]">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                    </svg>
                                </span>
                                <textarea
                                    v-model="form.message"
                                    rows="5"
                                    placeholder="Your Message *"
                                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 pl-11 pr-4 py-3 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition focus:border-[#0055B6] focus:ring-2 focus:ring-[#0055B6]/20 resize-y"
                                ></textarea>
                            </div>

                            <button
                                type="submit"
                                :disabled="sending"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[#0055B6] hover:bg-[#0047a0] px-6 py-3.5 text-sm font-bold text-white shadow-md shadow-[#0055B6]/25 transition active:scale-[0.99] disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                <svg v-if="!sending" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                </svg>
                                <svg v-else class="h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                {{ sending ? 'Sending…' : 'Send Message' }}
                            </button>
                        </form>

                        <div class="mt-5 flex items-center gap-4 rounded-xl bg-[#DCEBFF] dark:bg-[#0055B6]/15 border border-[#B6D5FF] dark:border-[#0055B6]/30 p-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white dark:bg-slate-900 text-[#0055B6] dark:text-[#60A5FA] shadow-sm">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-[#0B376B] dark:text-slate-100">Need immediate help?</p>
                                <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400 break-words">
                                    Call us at
                                    <a
                                        v-if="contactInfo.phone"
                                        :href="telLink(contactInfo.phone)"
                                        class="font-semibold text-[#0055B6] dark:text-[#60A5FA] hover:underline"
                                    >
                                        {{ contactInfo.phone }}
                                    </a>
                                    <span v-else class="font-semibold">the school phone</span>
                                    during office hours.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- FOOTER — PublicFooter Component -->
        <!-- ============================================================ -->
        <PublicFooter />
    </div>
</template>

<style scoped>
.school-page {
    --c-brand:   var(--brand);
    --c-heading: var(--brand-dark);
    --c-tint:    var(--brand-tint);
    --c-tint-2:  var(--brand-tint);
    --c-soft:    color-mix(in srgb, var(--brand) 10%, transparent);
    --c-page:    #ffffff;
}
:global(.dark) .school-page {
    --c-brand:   #93C5FD;
    --c-heading: #F1F5F9;
    --c-tint:    #0F172A;
    --c-tint-2:  #1E293B;
    --c-soft:    color-mix(in srgb, #60A5FA 22%, transparent);
    --c-page:    #020617;
}

:global(.dark) .text-navy { color: #F8FAFC !important; }
:global(.dark) .text-brand { color: #93C5FD !important; }

.reveal {
    opacity: 0;
    transform: translateY(28px);
    transition: opacity 0.8s cubic-bezier(0.22, 1, 0.36, 1), transform 0.8s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}
.reveal-left  { transform: translateX(-40px); }
.reveal-right { transform: translateX(40px); }
.reveal-zoom  { transform: scale(0.92); }
.reveal.is-visible { opacity: 1; transform: none; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (prefers-reduced-motion: reduce) {
    .reveal { opacity: 1 !important; transform: none !important; transition: none !important; animation: none !important; }
}
</style>