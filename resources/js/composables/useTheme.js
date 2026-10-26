import { ref, watch } from 'vue'

// ============================================================
// GLOBAL STATE — sob component e shared
// ============================================================
const theme = ref('light')     // 'light' | 'dark'
const isInitialized = ref(false)

// ============================================================
// APPLY THEME TO <html> element
// ============================================================
const applyTheme = (newTheme) => {
    if (typeof document === 'undefined') return

    const root = document.documentElement

    if (newTheme === 'dark') {
        root.classList.add('dark')
        root.style.colorScheme = 'dark'
    } else {
        root.classList.remove('dark')
        root.style.colorScheme = 'light'
    }

    theme.value = newTheme
}

// ============================================================
// INIT — read from localStorage or system preference
// ============================================================
const initTheme = () => {
    if (isInitialized.value) return
    if (typeof window === 'undefined') return

    const stored = localStorage.getItem('theme')

    let initial = 'light'

    if (stored === 'dark' || stored === 'light') {
        initial = stored
    } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        initial = 'dark'
    }

    applyTheme(initial)
    isInitialized.value = true
}

// ============================================================
// TOGGLE
// ============================================================
const toggleTheme = () => {
    const next = theme.value === 'dark' ? 'light' : 'dark'
    applyTheme(next)

    if (typeof window !== 'undefined') {
        localStorage.setItem('theme', next)
    }
}

// ============================================================
// SET SPECIFIC THEME
// ============================================================
const setTheme = (newTheme) => {
    applyTheme(newTheme)
    if (typeof window !== 'undefined') {
        localStorage.setItem('theme', newTheme)
    }
}

// ============================================================
// WATCH — persist any change
// ============================================================
watch(theme, (val) => {
    if (typeof window !== 'undefined') {
        localStorage.setItem('theme', val)
    }
})

// ============================================================
// EXPORT
// ============================================================
export function useTheme() {
    return {
        theme,
        isDark: () => theme.value === 'dark',
        initTheme,
        toggleTheme,
        setTheme,
    }
}