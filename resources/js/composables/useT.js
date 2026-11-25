import { usePage } from '@inertiajs/vue3'

/**
 * Translate a key of the student module: t('save'), t('fields.dob'), t('showing', { from: 1, to: 25, total: 90 }).
 * The labels come from lang/en/student.php or lang/bn/student.php, chosen by the language switch.
 * An unknown key shows the key itself, so a missing translation is visible instead of blank.
 */
export function useT() {
    const page = usePage()

    return (key, params = {}) => {
        const dict = page.props.i18n?.student ?? {}
        let value = key.split('.').reduce((node, part) => (node == null ? node : node[part]), dict)

        if (typeof value !== 'string') {
            return key
        }

        Object.entries(params).forEach(([name, replacement]) => {
            value = value.replaceAll(`:${name}`, String(replacement))
        })

        return value
    }
}
