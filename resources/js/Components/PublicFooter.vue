<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()

const school = computed(() => page.props.school ?? {})
const contact = computed(() => page.props.contact ?? {})

const year = new Date().getFullYear()

const quickLinks = [
    { name: 'Home',     href: '/' },
    { name: 'About',    href: '/about' },
    { name: 'Result',   href: '/result' },
    { name: 'Teachers', href: '/teachers' },
    { name: 'Contact',  href: '/contact' },
]

const scrollTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })
</script>

<template>
    <footer class="relative overflow-hidden bg-[#0B376B] dark:bg-slate-950 text-white transition-colors duration-300">
        <!-- Top gradient accent line -->
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-[#0055B6] via-[#1883DB] to-[#0055B6]"></div>

        <!-- Decorative wave -->
        <svg class="pointer-events-none absolute bottom-0 right-0 h-24 w-full sm:w-1/2 opacity-30" viewBox="0 0 600 100" preserveAspectRatio="none" aria-hidden="true">
            <path d="M600,10 C480,20 380,70 250,100 L600,100 Z" fill="#1883DB" />
        </svg>

        <div class="relative mx-auto max-w-[1320px] px-4 sm:px-6 lg:px-8 py-12 sm:py-14">
            <div class="grid grid-cols-1 gap-9 sm:grid-cols-2 sm:gap-x-10 lg:grid-cols-4 lg:gap-0 lg:divide-x lg:divide-white/10">

                <!-- Brand column -->
                <div class="min-w-0 lg:pr-8">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-white/10 ring-1 ring-white/20">
                            <img
                                v-if="school?.logo"
                                :src="`/storage/${school.logo}`"
                                :alt="school?.name"
                                class="h-full w-full object-contain p-1"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center bg-white/20 text-xl font-bold text-white">
                                {{ (school?.name || 'S').charAt(0) }}
                            </div>
                        </div>
                        <div class="min-w-0">
                            <p class="text-base font-bold leading-tight">{{ school?.name || 'School' }}</p>
                            <p v-if="school?.motto" class="text-[11px] font-medium text-[#F5B82E]">{{ school.motto }}</p>
                        </div>
                    </div>
                    <p class="text-sm leading-relaxed text-white/70">
                        {{ school?.short_description
                            || (school?.name || 'Our school') + ' is a trusted institution, committed to providing quality education and building a brighter future for every child.'
                        }}
                    </p>
                </div>

                <!-- Quick links -->
                <div class="min-w-0 lg:px-8">
                    <h4 class="mb-4 sm:mb-5 text-base font-bold text-white">Quick Links</h4>
                    <ul class="space-y-2.5">
                        <li v-for="link in quickLinks" :key="link.name">
                            <Link :href="link.href" class="group inline-flex items-center gap-2 py-0.5 text-sm text-white/75 transition hover:text-white">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10 text-[#BFDBFE] transition group-hover:bg-[#F5B82E] group-hover:text-[#0B376B]">
                                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7" />
                                    </svg>
                                </span>
                                {{ link.name }}
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Contact column -->
                <div class="min-w-0 lg:px-8">
                    <h4 class="mb-4 sm:mb-5 text-base font-bold text-white">Contact</h4>
                    <ul class="space-y-4 text-sm">
                        <li v-if="contact?.address || school?.address" class="flex items-center gap-3 text-white/75">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-[#F5B82E] ring-1 ring-white/20">
                                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </span>
                            <span class="min-w-0 break-words">{{ contact?.address || school?.address }}</span>
                        </li>

                        <li v-if="contact?.phone || school?.phone" class="flex items-center gap-3 text-white/75">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-[#F5B82E] ring-1 ring-white/20">
                                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </span>
                            <a :href="`tel:${(contact?.phone || school?.phone).replace(/\s+/g,'')}`" class="min-w-0 break-words hover:text-white">
                                {{ contact?.phone || school?.phone }}
                            </a>
                        </li>

                        <li v-if="contact?.email || school?.email" class="flex items-center gap-3 text-white/75">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-[#F5B82E] ring-1 ring-white/20">
                                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </span>
                            <a :href="`mailto:${contact?.email || school?.email}`" class="min-w-0 break-all hover:text-white">
                                {{ contact?.email || school?.email }}
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Follow us -->
                <div class="min-w-0 lg:pl-8">
                    <h4 class="mb-4 sm:mb-5 text-base font-bold text-white">Follow Us</h4>
                    <div class="flex flex-wrap items-center gap-3">
                        <a
                            v-if="contact?.facebook_url || school?.facebook_url"
                            :href="contact?.facebook_url || school?.facebook_url"
                            target="_blank"
                            rel="noopener"
                            aria-label="Facebook"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/25 transition hover:-translate-y-1 hover:bg-[#1877F2] hover:ring-[#1877F2]"
                        >
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.9h2.54V9.85c0-2.52 1.49-3.9 3.78-3.9 1.09 0 2.24.19 2.24.19v2.47h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.9h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z" /></svg>
                        </a>

                        <a
                            v-if="contact?.youtube_url || school?.youtube_url"
                            :href="contact?.youtube_url || school?.youtube_url"
                            target="_blank"
                            rel="noopener"
                            aria-label="YouTube"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/25 transition hover:-translate-y-1 hover:bg-red-600 hover:ring-red-600"
                        >
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M23.5 6.2a3 3 0 00-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 00.5 6.2 31.3 31.3 0 000 12a31.3 31.3 0 00.5 5.8 3 3 0 002.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 002.1-2.1A31.3 31.3 0 0024 12a31.3 31.3 0 00-.5-5.8zM9.6 15.6V8.4l6.2 3.6-6.2 3.6z" /></svg>
                        </a>

                        <a
                            v-if="contact?.instagram_url || school?.instagram_url"
                            :href="contact?.instagram_url || school?.instagram_url"
                            target="_blank"
                            rel="noopener"
                            aria-label="Instagram"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/25 transition hover:-translate-y-1 hover:bg-gradient-to-br hover:from-pink-500 hover:to-purple-600 hover:ring-transparent"
                        >
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23a3.7 3.7 0 01-.9 1.38 3.7 3.7 0 01-1.38.9c-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 01-1.38-.9 3.7 3.7 0 01-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 2.76a5.46 5.46 0 110 10.92 5.46 5.46 0 010-10.92zm0 9a3.54 3.54 0 100-7.08 3.54 3.54 0 000 7.08zm6.95-9.22a1.27 1.27 0 11-2.55 0 1.27 1.27 0 012.55 0z" /></svg>
                        </a>

                        <a
                            v-if="contact?.linkedin_url || school?.linkedin_url"
                            :href="contact?.linkedin_url || school?.linkedin_url"
                            target="_blank"
                            rel="noopener"
                            aria-label="LinkedIn"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/25 transition hover:-translate-y-1 hover:bg-[#0A66C2] hover:ring-[#0A66C2]"
                        >
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452z" /></svg>
                        </a>

                        <p
                            v-if="!(contact?.facebook_url || school?.facebook_url) && !(contact?.youtube_url || school?.youtube_url) && !(contact?.instagram_url || school?.instagram_url) && !(contact?.linkedin_url || school?.linkedin_url)"
                            class="text-sm text-white/60"
                        >
                            Social links will appear here once added.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom bar -->
        <div class="relative border-t border-white/10 bg-black/20">
            <div class="mx-auto flex max-w-[1320px] flex-col items-center justify-center gap-1 px-4 py-4 text-center text-xs text-white/70 sm:flex-row sm:gap-3">
                <p>© {{ year }} {{ school?.name || 'School' }}. All Rights Reserved.</p>
                <span class="hidden text-white/30 sm:inline">|</span>
                <p>
                    Developed By
                    <a
                        href="https://www.softniiq.com/"
                        target="_blank"
                        rel="noopener"
                        class="text-sm font-extrabold tracking-wide text-[#F5B82E] underline decoration-[#F5B82E]/60 underline-offset-4 transition hover:text-white hover:decoration-white"
                    >SoftNiiq</a>
                </p>
            </div>
        </div>
    </footer>
</template>