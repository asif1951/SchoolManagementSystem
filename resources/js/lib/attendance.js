/** Shared by the attendance screens: status looks, and dates and numbers the way the reader chose them. */
import { digits, label } from '@/lib/calendar'
import { ref, watch } from 'vue'

export const STATUSES = ['present', 'absent', 'late', 'leave']
// an icon and a colour for each, so a status is never told by colour alone
export const ICONS = { present: '✓', absent: '✕', late: '◔', leave: '⌂', partial: '◐' }
export const ON = { present: 'bg-green-700 text-white border-green-700', absent: 'bg-red-700 text-white border-red-700', late: 'bg-yellow-400 text-yellow-950 border-yellow-500', leave: 'bg-slate-700 text-white border-slate-700' }
export const SOFT = {
    present: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200', absent: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
    late: 'bg-yellow-100 text-yellow-900 dark:bg-yellow-950 dark:text-yellow-200', leave: 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
    partial: 'bg-orange-100 text-orange-900 dark:bg-orange-950 dark:text-orange-200',
}

const read = () => { try { return localStorage.getItem('calendar.mode') } catch { return null } }
const calendarMode = ref(read() || 'english')
watch(calendarMode, (m) => { try { localStorage.setItem('calendar.mode', m) } catch { /* private window */ } })
export const useCalendarMode = () => calendarMode

export const day = (d, mode = calendarMode.value, withYear = true) => (d ? label(d.slice(0, 10), mode, withYear) : '')
export const num = (v, locale) => (v === null || v === undefined || v === '' ? '—' : locale === 'bn' ? digits(v) : String(v))
export const stamp = (v, locale) => (v ? num(new Date(v.includes('T') ? v : v.replace(' ', 'T') + 'Z').toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }), locale) : '')
