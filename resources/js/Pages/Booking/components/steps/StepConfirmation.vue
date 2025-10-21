<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Форма записи</h3>

        <div v-if="selectedSlot" class="rounded-lg border border-secondary bg-yellow-50 px-4 py-3 text-sm text-primary space-y-1">
            <p><span class="font-medium">Дата:</span> {{ selectedDateLabel }}</p>
            <p><span class="font-medium">Время:</span> {{ selectedSlot.time }}</p>
            <p v-if="doctor"><span class="font-medium">Доктор:</span> {{ doctor.name }}</p>
            <p v-if="clinicName"><span class="font-medium">Клиника:</span> {{ clinicName }}</p>
            <p v-if="branchName"><span class="font-medium">Филиал:</span> {{ branchName }}</p>
        </div>

        <form class="space-y-3" @submit.prevent="handleSubmit">
            <BaseInput
                v-model="fioProxy"
                placeholder="ФИО"
                :error="fioError"
                @keydown="handleFioKeydown"
                @paste="handleFioPaste"
            />
            <BaseInput
                v-model="phoneProxy"
                placeholder="Телефон"
                :error="phoneError"
                @keydown="handlePhoneKeydown"
            />
            <div>
                <label :class="consentWrapperClasses">
                    <input
                        v-model="consentProxy"
                        type="checkbox"
                        :class="[
                            'mt-0.5 h-5 w-5 rounded focus:ring-primary focus:ring-offset-0 transition',
                            consentError ? 'border-red-500 text-red-500' : 'border-gray-300 text-primary'
                        ]"
                    >
                    <span>Согласие с обработкой персональных данных</span>
                </label>
                <p
                    v-if="consentError"
                    class="mt-1 text-xs text-red-500"
                >
                    {{ consentError }}
                </p>
            </div>
            <BaseButton
                class="w-full py-2"
                variant="primary"
                type="submit"
            >
                Записаться
            </BaseButton>
        </form>

        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import BaseButton from '../../../../Components/ui/BaseButton.vue'
import BaseInput from '../../../../Components/ui/BaseInput.vue'

const props = defineProps({
    selectedSlot: {
        type: Object,
        default: null,
    },
    selectedDateLabel: {
        type: String,
        default: '',
    },
    fio: {
        type: String,
        default: '',
    },
    phone: {
        type: String,
        default: '',
    },
    doctor: {
        type: Object,
        default: null,
    },
    clinicName: {
        type: String,
        default: '',
    },
    branchName: {
        type: String,
        default: '',
    },
    tgUserId: {
        type: [String, Number],
        default: null,
    },
    tgChatId: {
        type: [String, Number],
        default: null,
    },
    consent: {
        type: Boolean,
        default: false,
    },
})

const emits = defineEmits(['update:fio', 'update:phone', 'update:consent', 'submit', 'back'])

const submitAttempted = ref(false)
const fioError = ref('')
const phoneError = ref('')
const consentError = ref('')

const controlKeys = new Set(['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'Enter'])

const isModifierPressed = (event) => event.ctrlKey || event.metaKey || event.altKey

const handleFioKeydown = (event) => {
    if (controlKeys.has(event.key) || isModifierPressed(event)) {
        return
    }

    if (/^[А-Яа-яЁёA-Za-z]$/.test(event.key) || event.key === ' ' || event.key === '-') {
        return
    }

    event.preventDefault()
}

const handleFioPaste = (event) => {
    const pasted = (event.clipboardData || window.clipboardData).getData('text')

    if (!pasted || /^[А-Яа-яЁёA-Za-z\s-]+$/.test(pasted)) {
        return
    }

    event.preventDefault()

    const sanitized = sanitizeFio(pasted)

    const target = event.target

    requestAnimationFrame(() => {
        const start = target.selectionStart
        const end = target.selectionEnd

        const current = target.value
        const updated = `${current.slice(0, start)}${sanitized}${current.slice(end)}`

        emits('update:fio', sanitizeFio(updated))

        const newPosition = start + sanitized.length
        requestAnimationFrame(() => {
            target.setSelectionRange(newPosition, newPosition)
        })
    })
}

const sanitizeFio = (value) => {
    const sanitized = value.replace(/[^А-Яа-яЁёA-Za-z\s-]/g, '')

    if (value && sanitized !== value) {
        fioError.value = 'Можно вводить только буквы'
    } else if (!value && submitAttempted.value) {
        fioError.value = 'Укажите ФИО'
    } else {
        fioError.value = ''
    }

    return sanitized
}

const formatPhone = (value) => {
    const digitsOnly = value.replace(/\D/g, '')

    if (!digitsOnly.length) {
        phoneError.value = submitAttempted.value ? 'Укажите номер телефона' : ''
        return ''
    }

    let digits = digitsOnly

    if (digits.startsWith('8')) {
        digits = `7${digits.slice(1)}`
    } else if (!digits.startsWith('7')) {
        digits = `7${digits}`
    }

    digits = digits.slice(0, 11)

    let formatted = '+7'

    if (digits.length > 1) {
        formatted += ` (${digits.slice(1, 4)}`
    }

    if (digits.length >= 4) {
        formatted += ')'
    }

    if (digits.length > 4) {
        formatted += ` ${digits.slice(4, 7)}`
    }

    if (digits.length > 7) {
        formatted += `-${digits.slice(7, 9)}`
    }

    if (digits.length > 9) {
        formatted += `-${digits.slice(9, 11)}`
    }

    phoneError.value = digits.length === 11 ? '' : 'Введите 11 цифр номера'

    return formatted
}

const fioProxy = computed({
    get: () => props.fio,
    set: (value) => {
        const sanitized = sanitizeFio(value)
        emits('update:fio', sanitized)

        if (submitAttempted.value) {
            if (!sanitized.trim()) {
                fioError.value = 'Укажите ФИО'
            } else if (fioError.value === 'Укажите ФИО' || fioError.value === 'Можно вводить только буквы') {
                fioError.value = ''
            }
        }
    },
})

const handlePhoneKeydown = (event) => {
    if (controlKeys.has(event.key) || isModifierPressed(event)) {
        return
    }

    if (!/\d/.test(event.key)) {
        event.preventDefault()
        return
    }

    const digitsCount = event.target.value.replace(/\D/g, '').length

    if (digitsCount >= 11) {
        event.preventDefault()
    }
}

const phoneProxy = computed({
    get: () => props.phone,
    set: (value) => {
        const formatted = formatPhone(value)

        emits('update:phone', formatted)

        if (submitAttempted.value) {
            const digits = formatted.replace(/\D/g, '')

            if (!digits.length) {
                phoneError.value = 'Укажите номер телефона'
            } else if (digits.length !== 11) {
                phoneError.value = 'Введите 11 цифр номера'
            } else {
                phoneError.value = ''
            }
        }
    },
})

const consentProxy = computed({
    get: () => props.consent,
    set: (value) => {
        emits('update:consent', value)
        if (value) {
            consentError.value = ''
        }
    },
})

const validateFields = () => {
    let isValid = true

    const trimmedFio = (props.fio || '').trim()
    if (!trimmedFio) {
        fioError.value = 'Укажите ФИО'
        isValid = false
    } else if (fioError.value === 'Укажите ФИО' || fioError.value === 'Можно вводить только буквы') {
        fioError.value = ''
    }

    const phoneDigits = (props.phone || '').replace(/\D/g, '')
    if (!phoneDigits.length) {
        phoneError.value = 'Укажите номер телефона'
        isValid = false
    } else if (phoneDigits.length !== 11) {
        phoneError.value = 'Введите 11 цифр номера'
        isValid = false
    } else {
        phoneError.value = ''
    }

    return isValid && !fioError.value && !phoneError.value
}

const consentWrapperClasses = computed(() => [
    'flex items-center gap-3 rounded-lg border px-3 py-2 text-sm text-gray-700 cursor-pointer select-none transition focus-within:ring-2 focus-within:ring-primary focus-within:border-primary',
    consentError.value ? 'border-red-500' : 'border-gray-300',
])

const handleSubmit = () => {
    submitAttempted.value = true

    const fieldsValid = validateFields()

    if (!consentProxy.value) {
        consentError.value = 'Необходимо дать согласие на обработку персональных данных'
    } else {
        consentError.value = ''
    }

    if (!fieldsValid || consentError.value) {
        return
    }

    emits('submit')
}
</script>
