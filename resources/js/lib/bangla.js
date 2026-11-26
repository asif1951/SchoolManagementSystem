/** The official Bangladesh Bangla calendar (revised 2019). Mirrors app/Support/BanglaDate.php. Dates are 'YYYY-MM-DD'. */
export const MONTHS = ['বৈশাখ', 'জ্যৈষ্ঠ', 'আষাঢ়', 'শ্রাবণ', 'ভাদ্র', 'আশ্বিন', 'কার্তিক', 'অগ্রহায়ণ', 'পৌষ', 'মাঘ', 'ফাল্গুন', 'চৈত্র']

const DAY = 86400000
export const toNumber = (d) => Math.round(Date.parse(`${d}T00:00:00Z`) / DAY)
export const fromNumber = (n) => new Date(n * DAY).toISOString().slice(0, 10)
export const digits = (n) => String(n).replace(/\d/g, (c) => '০১২৩৪৫৬৭৮৯'[c])

export const monthLengths = (year) => {
    const g = year + 594
    const leap = (g % 4 === 0 && g % 100 !== 0) || g % 400 === 0
    return [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, leap ? 30 : 29, 30]
}

export const toBangla = (date) => {
    const gy = Number(date.slice(0, 4))
    const year = gy - (date.slice(5) >= '04-14' ? 593 : 594)
    let passed = toNumber(date) - toNumber(`${year + 593}-04-14`)
    const lengths = monthLengths(year)
    for (let i = 0; i < 12; i++) {
        if (passed < lengths[i]) return { year, month: i + 1, day: passed + 1 }
        passed -= lengths[i]
    }
    return { year, month: 12, day: 30 }
}

export const toGregorian = (year, month, day) => {
    const lengths = monthLengths(year)
    const d = Math.min(Math.max(1, day), lengths[month - 1])
    return fromNumber(toNumber(`${year + 593}-04-14`) + lengths.slice(0, month - 1).reduce((a, b) => a + b, 0) + d - 1)
}

export const format = (date, withYear = false) => {
    const b = toBangla(date)
    return `${digits(b.day)} ${MONTHS[b.month - 1]}${withYear ? ` ${digits(b.year)}` : ''}`
}
