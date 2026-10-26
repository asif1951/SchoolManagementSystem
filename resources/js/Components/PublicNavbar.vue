<script setup>
import { Link, usePage, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useTheme } from '@/composables/useTheme'

const page = usePage()

// ============================================================
// THEME
// ============================================================
const { theme, toggleTheme, initTheme } = useTheme()

onMounted(() => {
    initTheme()
})

// ============================================================
// SCHOOL DATA
// ============================================================
const school = computed(() => page.props.school ?? {})

const schoolName = computed(() => school.value?.name ?? 'School Management')
const schoolLogo = computed(() =>
    school.value?.logo ? `/storage/${school.value.logo}` : null
)

// ============================================================
// AUTH
// ============================================================
const user = computed(() => page.props.auth?.user)

const isStaff = computed(() => {
    const role = user.value?.role
    return role === 'admin' || role === 'teacher'
})

// ============================================================
// USER DROPDOWN (desktop)
// ============================================================
const userDropdownOpen = ref(false)

const toggleUserDropdown = () => {
    userDropdownOpen.value = !userDropdownOpen.value
}

const dropdownRef = ref(null)

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        userDropdownOpen.value = false
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside)
})

// ============================================================
// MOBILE MENU
// ============================================================
const mobileMenuOpen = ref(false)

const closeMobile = () => {
    mobileMenuOpen.value = false
    userDropdownOpen.value = false
}

watch(() => page.url, () => {
    closeMobile()
})

// ============================================================
// LOGOUT
// ============================================================
const logout = () => {
    closeMobile()
    userDropdownOpen.value = false
    router.post(route('logout'))
}

// ============================================================
// NAVIGATION LINKS
// ============================================================
const links = [
    { name: 'Home',     href: '/' },
    { name: 'About',    href: '/about' },
    { name: 'Result',   href: '/result' },
    { name: 'Teachers', href: '/teachers' },
    { name: 'Contact',  href: '/contact' },
]

// ============================================================
// ACTIVE LINK CHECK
// ============================================================
const isActive = (link) => {
    const current = page.url.split('?')[0]
    if (link.href === '/') return current === '/'
    return current === link.href || current.startsWith(link.href + '/')
}

const navLinkClass = (link) => {
    const base = 'relative text-[15px] font-medium transition-all duration-300 pb-1 whitespace-nowrap'
    return isActive(link)
        ? `${base} text-[#0055B6] dark:text-[#60A5FA] font-semibold`
        : `${base} text-[#0B376B] dark:text-slate-300 hover:text-[#0055B6] dark:hover:text-[#60A5FA]`
}
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-slate-200/60 dark:border-slate-800/60 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl shadow-[0_1px_3px_0_rgba(15,23,42,0.04)] dark:shadow-[0_1px_3px_0_rgba(0,0,0,0.2)] transition-colors duration-300">
        <div class="mx-auto max-w-[1320px] px-3 xs:px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 xs:h-[72px] sm:h-20 lg:h-[88px] items-center justify-between gap-2 xs:gap-3 sm:gap-4">

                <!-- LEFT: LOGO + SCHOOL NAME -->
                <Link
                    :href="route('home')"
                    class="flex items-center gap-2 xs:gap-2.5 sm:gap-3 lg:gap-4 min-w-0 flex-shrink-0 group"
                >
                    <div class="flex h-10 w-10 xs:h-11 xs:w-11 sm:h-14 sm:w-14 lg:h-16 lg:w-16 xl:h-[72px] xl:w-[72px] items-center justify-center rounded-xl xs:rounded-2xl bg-white dark:bg-slate-800 flex-shrink-0 overflow-hidden ring-1 ring-slate-200/60 dark:ring-slate-700/60 shadow-sm transition-all duration-300 group-hover:shadow-md group-hover:ring-[#0055B6]/30 dark:group-hover:ring-[#60A5FA]/30">
                        <img
                            v-if="schoolLogo"
                            :src="schoolLogo"
                            :alt="schoolName"
                            class="h-full w-full object-contain p-1 transition-transform duration-300 group-hover:scale-105"
                        />
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center rounded-xl xs:rounded-2xl bg-gradient-to-br from-[#0055B6] to-[#1883DB] text-white font-bold text-sm xs:text-base sm:text-xl lg:text-2xl"
                        >
                            {{ schoolName.charAt(0).toUpperCase() }}
                        </div>
                    </div>

                    <div class="min-w-0 hidden min-[380px]:block">
                        <h1 class="text-sm xs:text-base sm:text-lg lg:text-xl xl:text-2xl font-bold bg-gradient-to-r from-[#0055B6] to-[#1883DB] dark:from-[#60A5FA] dark:to-[#93C5FD] bg-clip-text text-transparent leading-tight truncate max-w-[140px] xs:max-w-[180px] sm:max-w-[240px] lg:max-w-none">
                            {{ schoolName }}
                        </h1>
                    </div>
                </Link>

                <!-- CENTER: DESKTOP MENU -->
                <nav class="hidden lg:flex items-center gap-1 xl:gap-2 2xl:gap-3 rounded-full bg-slate-50/80 dark:bg-slate-800/50 px-2 py-1.5 ring-1 ring-slate-200/50 dark:ring-slate-700/50">
                    <Link
                        v-for="link in links"
                        :key="link.name"
                        :href="link.href"
                        :class="[
                            'relative px-3 xl:px-4 py-1.5 rounded-full text-[14px] xl:text-[15px] font-medium transition-all duration-300 whitespace-nowrap',
                            isActive(link)
                                ? 'text-white bg-gradient-to-r from-[#0055B6] to-[#1883DB] shadow-sm shadow-[#0055B6]/20'
                                : 'text-[#0B376B] dark:text-slate-300 hover:text-[#0055B6] dark:hover:text-[#60A5FA] hover:bg-white dark:hover:bg-slate-700/50'
                        ]"
                    >
                        {{ link.name }}
                    </Link>
                </nav>

                <!-- RIGHT: THEME + AUTH -->
                <div class="hidden lg:flex items-center gap-2 xl:gap-3 flex-shrink-0">

                    <!-- ============================================ -->
                    <!-- ENHANCED THEME TOGGLE -->
                    <!-- ============================================ -->
                    <button
                        type="button"
                        @click="toggleTheme"
                        role="switch"
                        :aria-checked="theme === 'dark'"
                        :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                        class="theme-toggle relative inline-flex h-9 w-[68px] flex-shrink-0 items-center rounded-full transition-all duration-500 ease-out active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0055B6]/40 dark:focus-visible:ring-[#60A5FA]/40"
                        :class="theme === 'dark' 
                            ? 'bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-700 shadow-inner shadow-indigo-900/30' 
                            : 'bg-gradient-to-r from-sky-200 via-amber-100 to-orange-200 shadow-inner shadow-amber-200/50'"
                    >
                        <!-- Stars (dark mode) -->
                        <Transition
                            enter-active-class="transition duration-500 delay-100"
                            enter-from-class="opacity-0 scale-50"
                            enter-to-class="opacity-100 scale-100"
                            leave-active-class="transition duration-200"
                            leave-from-class="opacity-100 scale-100"
                            leave-to-class="opacity-0 scale-50"
                        >
                            <div v-if="theme === 'dark'" class="absolute inset-0 overflow-hidden rounded-full">
                                <span class="absolute top-1.5 left-3 h-0.5 w-0.5 rounded-full bg-white/70"></span>
                                <span class="absolute top-3 left-5 h-0.5 w-0.5 rounded-full bg-white/50"></span>
                                <span class="absolute bottom-2 left-4 h-0.5 w-0.5 rounded-full bg-white/60"></span>
                                <span class="absolute top-2 right-5 h-0.5 w-0.5 rounded-full bg-white/40"></span>
                            </div>
                        </Transition>

                        <!-- Clouds (light mode) -->
                        <Transition
                            enter-active-class="transition duration-500 delay-100"
                            enter-from-class="opacity-0 -translate-x-2"
                            enter-to-class="opacity-100 translate-x-0"
                            leave-active-class="transition duration-200"
                            leave-from-class="opacity-100 translate-x-0"
                            leave-to-class="opacity-0 -translate-x-2"
                        >
                            <div v-if="theme === 'light'" class="absolute inset-0 overflow-hidden rounded-full">
                                <svg class="absolute top-1.5 left-2 h-2.5 w-4 text-white/70" viewBox="0 0 24 12" fill="currentColor">
                                    <ellipse cx="8" cy="8" rx="8" ry="4" />
                                    <ellipse cx="14" cy="6" rx="6" ry="3.5" />
                                </svg>
                                <svg class="absolute bottom-1.5 right-3 h-2 w-3.5 text-white/50" viewBox="0 0 24 12" fill="currentColor">
                                    <ellipse cx="8" cy="8" rx="8" ry="4" />
                                    <ellipse cx="14" cy="6" rx="6" ry="3.5" />
                                </svg>
                            </div>
                        </Transition>

                        <!-- Toggle knob -->
                        <span
                            class="absolute left-1 flex h-7 w-7 items-center justify-center rounded-full shadow-lg transition-all duration-500 ease-out"
                            :class="[
                                theme === 'dark' 
                                    ? 'translate-x-[32px] bg-gradient-to-br from-slate-700 to-slate-900 ring-1 ring-white/20' 
                                    : 'translate-x-0 bg-gradient-to-br from-amber-300 to-orange-400 ring-1 ring-white/50'
                            ]"
                        >
                            <!-- Sun icon -->
                            <svg
                                v-if="theme === 'light'"
                                class="h-4 w-4 text-amber-700 transition-transform duration-500"
                                fill="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.758 17.303a.75.75 0 00-1.061-1.06l-1.591 1.59a.75.75 0 001.06 1.061l1.591-1.59zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.697 7.757a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591z" />
                            </svg>
                            <!-- Moon icon -->
                            <svg
                                v-else
                                class="h-4 w-4 text-indigo-200 transition-transform duration-500"
                                fill="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 01.162.819A8.97 8.97 0 009 6a9 9 0 009 9 8.97 8.97 0 003.463-.69.75.75 0 01.981.98 10.503 10.503 0 01-9.694 6.46c-5.799 0-10.5-4.701-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 01.818.162z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </button>

                    <!-- STAFF -->
                    <template v-if="user && isStaff">
                        <span class="text-xs xl:text-sm font-medium text-slate-600 dark:text-slate-300 hidden xl:inline max-w-[100px] truncate">
                            Hi, {{ user.name }}
                        </span>
                        <Link
                            :href="route('dashboard')"
                            class="inline-flex items-center gap-1.5 xl:gap-2 rounded-full bg-gradient-to-r from-[#0055B6] to-[#1883DB] px-3 xl:px-5 2xl:px-6 py-1.5 xl:py-2 2xl:py-2.5 text-xs xl:text-sm font-semibold text-white shadow-md shadow-[#0055B6]/20 transition-all duration-300 hover:shadow-lg hover:shadow-[#0055B6]/30 hover:scale-[1.03] active:scale-95"
                        >
                            <svg class="h-3.5 w-3.5 xl:h-4 xl:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z" />
                            </svg>
                            <span class="hidden xl:inline">Dashboard</span>
                            <span class="xl:hidden">Dash</span>
                        </Link>

                        <button
                            type="button"
                            @click="logout"
                            title="Logout"
                            class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 xl:px-4 py-1.5 xl:py-2 text-xs xl:text-sm font-semibold text-rose-600 dark:text-rose-400 hover:border-rose-300 dark:hover:border-rose-500/50 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-all duration-300 active:scale-95"
                        >
                            <svg class="h-3.5 w-3.5 xl:h-4 xl:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span class="hidden xl:inline">Logout</span>
                        </button>
                    </template>

                    <!-- NORMAL USER -->
                    <template v-else-if="user">
                        <div ref="dropdownRef" class="relative">
                            <button
                                type="button"
                                @click.stop="toggleUserDropdown"
                                class="inline-flex items-center gap-2 rounded-full bg-slate-50 dark:bg-slate-800 pl-1 pr-3 xl:pl-1.5 xl:pr-4 py-1 xl:py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 hover:border-slate-300 dark:hover:border-slate-600 transition-all duration-300 active:scale-95"
                            >
                                <div class="flex h-7 w-7 xl:h-8 xl:w-8 items-center justify-center rounded-full bg-gradient-to-br from-[#0055B6] to-[#1883DB] text-white text-[11px] xl:text-xs font-bold flex-shrink-0 shadow-sm">
                                    {{ user.name?.charAt(0)?.toUpperCase() }}
                                </div>
                                <span class="text-xs xl:text-sm font-semibold text-slate-700 dark:text-slate-200 max-w-[100px] xl:max-w-[120px] truncate">
                                    {{ user.name }}
                                </span>
                                <svg
                                    class="h-3.5 w-3.5 xl:h-4 xl:w-4 text-slate-500 dark:text-slate-400 transition-transform duration-300"
                                    :class="userDropdownOpen ? 'rotate-180' : ''"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <Transition
                                enter-active-class="transition duration-200 ease-out"
                                enter-from-class="opacity-0 -translate-y-2 scale-95"
                                enter-to-class="opacity-100 translate-y-0 scale-100"
                                leave-active-class="transition duration-150 ease-in"
                                leave-from-class="opacity-100 translate-y-0 scale-100"
                                leave-to-class="opacity-0 -translate-y-2 scale-95"
                            >
                                <div
                                    v-if="userDropdownOpen"
                                    class="absolute right-0 top-full mt-2 w-56 rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-xl shadow-slate-900/10 dark:shadow-black/30 overflow-hidden z-50"
                                >
                                    <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-br from-slate-50 to-slate-100/50 dark:from-slate-800/80 dark:to-slate-800/30">
                                        <p class="text-sm font-bold text-slate-800 dark:text-slate-100 truncate">{{ user.name }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">{{ user.email }}</p>
                                    </div>

                                    <Link
                                        :href="route('profile.edit')"
                                        @click="userDropdownOpen = false"
                                        class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors duration-200"
                                    >
                                        <svg class="h-4 w-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                        Profile
                                    </Link>

                                    <button
                                        type="button"
                                        @click="logout"
                                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors duration-200 border-t border-slate-100 dark:border-slate-800"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                        </svg>
                                        Logout
                                    </button>
                                </div>
                            </Transition>
                        </div>
                    </template>

                    <!-- Guest -->
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center gap-1 xl:gap-2 rounded-full border-2 border-[#0055B6]/20 dark:border-[#60A5FA]/30 bg-white dark:bg-slate-800 px-3 xl:px-5 2xl:px-6 py-1.5 xl:py-2 2xl:py-2.5 text-xs xl:text-sm font-semibold text-[#0055B6] dark:text-[#60A5FA] transition-all duration-300 hover:border-[#0055B6]/40 dark:hover:border-[#60A5FA]/50 hover:bg-[#0055B6]/5 dark:hover:bg-[#60A5FA]/10 hover:scale-[1.03] active:scale-95"
                        >
                            <svg class="h-3.5 w-3.5 xl:h-4 xl:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            Login
                        </Link>

                        <Link
                            :href="route('register')"
                            class="inline-flex items-center gap-1 xl:gap-2 rounded-full bg-gradient-to-r from-[#0055B6] to-[#1883DB] px-3 xl:px-5 2xl:px-6 py-1.5 xl:py-2 2xl:py-2.5 text-xs xl:text-sm font-semibold text-white shadow-md shadow-[#0055B6]/20 transition-all duration-300 hover:shadow-lg hover:shadow-[#0055B6]/30 hover:scale-[1.03] active:scale-95"
                        >
                            <svg class="h-3.5 w-3.5 xl:h-4 xl:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                            <span class="hidden sm:inline">Register</span>
                            <span class="sm:hidden">Join</span>
                        </Link>
                    </template>
                </div>

                <!-- MOBILE: THEME + HAMBURGER -->
                <div class="lg:hidden flex items-center gap-1.5 xs:gap-2 flex-shrink-0">

                    <!-- Mobile Theme Toggle -->
                    <button
                        type="button"
                        @click="toggleTheme"
                        role="switch"
                        :aria-checked="theme === 'dark'"
                        :title="theme === 'dark' ? 'Light mode' : 'Dark mode'"
                        class="theme-toggle relative inline-flex h-8 w-[60px] flex-shrink-0 items-center rounded-full transition-all duration-500 ease-out active:scale-95"
                        :class="theme === 'dark' 
                            ? 'bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-700 shadow-inner shadow-indigo-900/30' 
                            : 'bg-gradient-to-r from-sky-200 via-amber-100 to-orange-200 shadow-inner shadow-amber-200/50'"
                    >
                        <span class="absolute top-1 left-2.5 h-0.5 w-0.5 rounded-full bg-white/70" :class="theme === 'dark' ? 'opacity-100' : 'opacity-0'" style="transition: opacity 0.3s"></span>
                        <span class="absolute bottom-1.5 left-4 h-0.5 w-0.5 rounded-full bg-white/50" :class="theme === 'dark' ? 'opacity-100' : 'opacity-0'" style="transition: opacity 0.3s"></span>

                        <span
                            class="absolute left-0.5 flex h-6 w-6 items-center justify-center rounded-full shadow-lg transition-all duration-500 ease-out"
                            :class="[
                                theme === 'dark' 
                                    ? 'translate-x-[28px] bg-gradient-to-br from-slate-700 to-slate-900 ring-1 ring-white/20' 
                                    : 'translate-x-0 bg-gradient-to-br from-amber-300 to-orange-400 ring-1 ring-white/50'
                            ]"
                        >
                            <svg
                                v-if="theme === 'light'"
                                class="h-3.5 w-3.5 text-amber-700"
                                fill="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.758 17.303a.75.75 0 00-1.061-1.06l-1.591 1.59a.75.75 0 001.06 1.061l1.591-1.59zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.697 7.757a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591z" />
                            </svg>
                            <svg
                                v-else
                                class="h-3.5 w-3.5 text-indigo-200"
                                fill="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 01.162.819A8.97 8.97 0 009 6a9 9 0 009 9 8.97 8.97 0 003.463-.69.75.75 0 01.981.98 10.503 10.503 0 01-9.694 6.46c-5.799 0-10.5-4.701-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 01.818.162z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="mobileMenuOpen = !mobileMenuOpen"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-2 xs:p-2.5 text-[#0055B6] dark:text-[#60A5FA] transition-all duration-300 hover:bg-slate-50 dark:hover:bg-slate-700 active:scale-95 flex-shrink-0"
                        :aria-expanded="mobileMenuOpen"
                        aria-label="Toggle menu"
                    >
                        <svg v-if="!mobileMenuOpen" class="h-5 w-5 xs:h-6 xs:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg v-else class="h-5 w-5 xs:h-6 xs:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- MOBILE MENU -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 -translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-4"
        >
            <div
                v-if="mobileMenuOpen"
                class="lg:hidden border-t border-slate-200/60 dark:border-slate-800/60 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl shadow-lg max-h-[calc(100vh-64px)] xs:max-h-[calc(100vh-72px)] overflow-y-auto"
            >
                <div class="px-3 xs:px-4 sm:px-6 py-3 xs:py-4 space-y-1">

                    <Link
                        v-for="link in links"
                        :key="link.name"
                        :href="link.href"
                        @click="closeMobile"
                        :class="[
                            'flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium transition-all duration-300',
                            isActive(link)
                                ? 'bg-gradient-to-r from-[#0055B6]/10 to-[#1883DB]/10 dark:from-[#60A5FA]/15 dark:to-[#93C5FD]/15 text-[#0055B6] dark:text-[#60A5FA] font-semibold ring-1 ring-[#0055B6]/10 dark:ring-[#60A5FA]/20'
                                : 'text-[#0B376B] dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'
                        ]"
                    >
                        <span>{{ link.name }}</span>
                        <svg
                            v-if="isActive(link)"
                            class="h-4 w-4 text-[#0055B6] dark:text-[#60A5FA]"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                        <svg
                            v-else
                            class="h-4 w-4 text-slate-400 dark:text-slate-500"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </Link>

                    <div class="pt-3 mt-3 border-t border-slate-100 dark:border-slate-800 space-y-2">

                        <!-- STAFF -->
                        <template v-if="user && isStaff">
                            <div class="flex items-center gap-3 px-3 xs:px-4 py-2 mb-2">
                                <div class="flex h-9 w-9 xs:h-10 xs:w-10 items-center justify-center rounded-full bg-gradient-to-br from-[#0055B6] to-[#1883DB] text-white text-xs xs:text-sm font-bold flex-shrink-0">
                                    {{ user.name?.charAt(0)?.toUpperCase() }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs xs:text-sm font-bold text-slate-800 dark:text-slate-100 truncate">{{ user.name }}</p>
                                    <p class="text-[10px] xs:text-xs text-slate-500 dark:text-slate-400 truncate">{{ user.email }}</p>
                                </div>
                            </div>

                            <Link
                                :href="route('dashboard')"
                                @click="closeMobile"
                                class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#0055B6] to-[#1883DB] px-4 xs:px-5 py-2.5 xs:py-3 text-xs xs:text-sm font-semibold text-white shadow-md shadow-[#0055B6]/20 transition-all duration-300 hover:shadow-lg active:scale-95"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5z" />
                                </svg>
                                Go to Dashboard
                            </Link>

                            <button
                                type="button"
                                @click="logout"
                                class="w-full flex items-center justify-center gap-2 rounded-xl border-2 border-rose-200 dark:border-rose-500/30 bg-white dark:bg-slate-800 px-4 xs:px-5 py-2.5 xs:py-3 text-xs xs:text-sm font-semibold text-rose-600 dark:text-rose-400 transition-all duration-300 hover:bg-rose-50 dark:hover:bg-rose-900/20 active:scale-95"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                                Logout
                            </button>
                        </template>

                        <!-- NORMAL USER -->
                        <template v-else-if="user">
                            <div class="flex items-center gap-3 px-3 xs:px-4 py-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700">
                                <div class="flex h-10 w-10 xs:h-11 xs:w-11 items-center justify-center rounded-full bg-gradient-to-br from-[#0055B6] to-[#1883DB] text-white text-sm xs:text-base font-bold flex-shrink-0">
                                    {{ user.name?.charAt(0)?.toUpperCase() }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs xs:text-sm font-bold text-slate-800 dark:text-slate-100 truncate">{{ user.name }}</p>
                                    <p class="text-[10px] xs:text-xs text-slate-500 dark:text-slate-400 truncate">{{ user.email }}</p>
                                </div>
                            </div>

                            <Link
                                :href="route('profile.edit')"
                                @click="closeMobile"
                                class="flex items-center justify-center gap-2 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 xs:px-5 py-2.5 xs:py-3 text-xs xs:text-sm font-semibold text-slate-700 dark:text-slate-300 transition-all duration-300 hover:bg-slate-50 dark:hover:bg-slate-700 active:scale-95"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                Profile
                            </Link>

                            <button
                                type="button"
                                @click="logout"
                                class="w-full flex items-center justify-center gap-2 rounded-xl border-2 border-rose-200 dark:border-rose-500/30 bg-white dark:bg-slate-800 px-4 xs:px-5 py-2.5 xs:py-3 text-xs xs:text-sm font-semibold text-rose-600 dark:text-rose-400 transition-all duration-300 hover:bg-rose-50 dark:hover:bg-rose-900/20 active:scale-95"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                                Logout
                            </button>
                        </template>

                        <!-- Guest -->
                        <template v-else>
                            <Link
                                :href="route('login')"
                                @click="closeMobile"
                                class="flex items-center justify-center gap-2 rounded-xl border-2 border-[#0055B6]/20 dark:border-[#60A5FA]/30 bg-white dark:bg-slate-800 px-4 xs:px-5 py-2.5 xs:py-3 text-xs xs:text-sm font-semibold text-[#0055B6] dark:text-[#60A5FA] transition-all duration-300 hover:border-[#0055B6]/40 dark:hover:border-[#60A5FA]/50 hover:bg-[#0055B6]/5 dark:hover:bg-[#60A5FA]/10 active:scale-95"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                Login
                            </Link>

                            <Link
                                :href="route('register')"
                                @click="closeMobile"
                                class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#0055B6] to-[#1883DB] px-4 xs:px-5 py-2.5 xs:py-3 text-xs xs:text-sm font-semibold text-white shadow-md shadow-[#0055B6]/20 transition-all duration-300 hover:shadow-lg active:scale-95"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                                </svg>
                                Register
                            </Link>
                        </template>
                    </div>
                </div>
            </div>
        </Transition>
    </header>
</template>

<style scoped>
a {
    transition: color 0.2s ease;
}
</style>