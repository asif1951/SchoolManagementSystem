/** Shared by the calendar screens: dates as 'YYYY-MM-DD', shown in English, Bangla or both. */
import { digits, format as bnFormat, fromNumber, toNumber } from '@/lib/bangla'

export const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
export const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5]          // the school week starts on Saturday

export const addDays = (d, n) => fromNumber(toNumber(d) + n)
export const diffDays = (a, b) => toNumber(b) - toNumber(a)
export const weekday = (d) => new Date(`${d}T00:00:00Z`).getUTCDay()
export const english = (d, options = { day: 'numeric', month: 'short', year: 'numeric' }) => new Date(`${d}T00:00:00Z`).toLocaleDateString('en-GB', { timeZone: 'UTC', ...options })

/** one date in the chosen calendar(s) */
export const label = (d, mode = 'english', withYear = true) => {
    const en = english(d, withYear ? { day: 'numeric', month: 'short', year: 'numeric' } : { day: 'numeric', month: 'short' })
    if (mode === 'english') return en
    return mode === 'bangla' ? bnFormat(d, withYear) : `${en} · ${bnFormat(d, withYear)}`
}
export const range = (a, b, mode, withYear = false) => (a === b ? label(a, mode, withYear) : `${label(a, mode, withYear)} – ${label(b, mode, withYear)}`)
export const dayNumber = (d, mode) => (mode === 'bangla' ? bnFormat(d).split(' ')[0] : String(Number(d.slice(8))))
export { digits }

// the violet theme turns every blue-like Tailwind colour violet, so the types use hues that stay distinct
export const COLORS = {
    red: 'bg-red-100 text-red-900 border-red-300', orange: 'bg-orange-100 text-orange-900 border-orange-300', black: 'bg-slate-800 text-white border-slate-900',
    teal: 'bg-emerald-200 text-emerald-950 border-emerald-500', violet: 'bg-brand-100 text-brand-900 border-brand-300', slate: 'bg-slate-200 text-slate-800 border-slate-300',
    blue: 'bg-pink-100 text-pink-900 border-pink-300', green: 'bg-green-100 text-green-900 border-green-300', indigo: 'bg-lime-100 text-lime-900 border-lime-400',
    cyan: 'bg-teal-100 text-teal-900 border-teal-300', amber: 'bg-yellow-100 text-yellow-900 border-yellow-400', grey: 'bg-slate-100 text-slate-700 border-slate-300',
}
export const DOTS = {
    red: 'bg-red-500', orange: 'bg-orange-500', black: 'bg-slate-900', teal: 'bg-emerald-700', violet: 'bg-brand-600', slate: 'bg-slate-400',
    blue: 'bg-pink-500', green: 'bg-green-500', indigo: 'bg-lime-500', cyan: 'bg-teal-500', amber: 'bg-yellow-400', grey: 'bg-slate-300',
}
