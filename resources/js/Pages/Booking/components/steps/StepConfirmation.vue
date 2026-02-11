<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Форма записи</h3>

        <!-- Блок с информацией о выборе слота/клиники отображаем только когда это требуется текущим шагом -->
        <div v-if="showSelectionDetails">
            <div v-if="selectedSlot" class="rounded-lg border border-secondary bg-yellow-50 px-4 py-3 text-sm text-primary space-y-1">
                <p><span class="font-medium">Дата:</span> {{ selectedDateLabel }}</p>
                <p><span class="font-medium">Время:</span> {{ selectedSlot.time }}</p>
                <p v-if="doctor"><span class="font-medium">Доктор:</span> {{ doctor.name }}</p>
                <p v-if="clinicName"><span class="font-medium">Клиника:</span> {{ clinicName }}</p>
                <p v-if="branchName"><span class="font-medium">Филиал:</span> {{ branchName }}</p>
            </div>
            <div
                v-else
                class="rounded-lg border border-secondary bg-yellow-50 px-4 py-3 text-sm text-primary space-y-1"
            >
                <p v-if="doctor"><span class="font-medium">Доктор:</span> {{ doctor.name }}</p>
                <p v-if="clinicName"><span class="font-medium">Клиника:</span> {{ clinicName }}</p>
                <p v-if="branchName"><span class="font-medium">Филиал:</span> {{ branchName }}</p>
                <p>
                    Запись будет оформлена без выбора
                    <span v-if="doctor">времени.</span>
                    <span v-else>времени и доктора.</span>
                </p>
            </div>
        </div>

        <!-- Форма редактирования персональных данных используется только на первом шаге -->
        <form
            v-if="showForm"
            class="space-y-3"
            @submit.prevent="handleSubmit"
        >
            <BaseInput
                v-model="fioProxy"
                placeholder="ФИО родителя"
                :error="fioError"
                @keydown="handleFioKeydown"
                @paste="handleParentFioPaste"
            />
            <BaseInput
                v-model="childFioProxy"
                placeholder="ФИО ребенка"
                :error="childFioError"
                @keydown="handleFioKeydown"
                @paste="handleChildFioPaste"
            />
            <BaseInput
                v-model="phoneProxy"
                placeholder="Телефон"
                :error="phoneError"
                @keydown="handlePhoneKeydown"
            />
            <BaseInput
                v-model="promoCodeProxy"
                placeholder="Промокод"
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
                {{ submitLabel }}
            </BaseButton>
        </form>

        <!-- Финальный шаг показывает только сводные данные и кнопку отправки -->
        <div
            v-else
            class="space-y-3"
        >
            <div class="rounded-lg border border-secondary bg-gray-50 px-4 py-3 text-sm text-gray-800 space-y-2">
                <p><span class="font-medium">ФИО родителя:</span> {{ fio || 'Не указано' }}</p>
                <p><span class="font-medium">ФИО ребенка:</span> {{ childFio || 'Не указано' }}</p>
                <p><span class="font-medium">Телефон:</span> {{ phone || 'Не указан' }}</p>
                <p><span class="font-medium">Промокод:</span> {{ promoCode || 'Не указан' }}</p>
                <p><span class="font-medium">Согласие:</span> {{ consent ? 'Получено' : 'Не дано' }}</p>
            </div>
            <BaseButton
                class="w-full py-2"
                variant="primary"
                type="button"
                @click="handleSubmit"
            >
                {{ submitLabel }}
            </BaseButton>
        </div>

        <BaseButton
            v-if="showBackButton"
            variant="ghost"
            class="text-sm"
            @click="$emit('back')"
        >
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import BaseButton from '../../../../Components/ui/BaseButton.vue'
import BaseInput from '../../../../Components/ui/BaseInput.vue'

// Универсальный шаг мастера: умеет работать как интерактивная форма и как финальный обзор.
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
    childFio: {
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
    promoCode: {
        type: String,
        default: '',
    },
    showSelectionDetails: {
        type: Boolean,
        default: true,
    },
    showForm: {
        type: Boolean,
        default: true,
    },
    submitLabel: {
        type: String,
        default: 'Записаться',
    },
    showBackButton: {
        type: Boolean,
        default: true,
    },
})

const emits = defineEmits(['update:fio', 'update:childFio', 'update:phone', 'update:consent', 'update:promoCode', 'submit', 'back'])

// Сервисные переменные для отслеживания ошибок и повторной валидации
const submitAttempted = ref(false)
const fioError = ref('')
const childFioError = ref('')
const phoneError = ref('')
const consentError = ref('')

const controlKeys = new Set(['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'Enter'])
const parentFioEmptyMessage = 'Укажите ФИО родителя'
const childFioEmptyMessage = 'Укажите ФИО ребенка'

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

const sanitizeFio = (value) => {
    if (!value) {
        return ''
    }

    return String(value).replace(/[^А-Яа-яЁёA-Za-z\s-]/g, '')
}

const updateFioValue = (propName, value, errorRef, emptyMessage) => {
    const raw = value === null || value === undefined ? '' : String(value)
    const sanitized = sanitizeFio(raw)

    emits(`update:${propName}`, sanitized)

    if (raw && sanitized !== raw) {
        errorRef.value = 'Можно вводить только буквы'
    } else if (submitAttempted.value && !sanitized.trim()) {
        errorRef.value = emptyMessage
    } else if (errorRef.value === emptyMessage || errorRef.value === 'Можно вводить только буквы') {
        errorRef.value = ''
    }

    return sanitized
}

const createFioProxy = (propName, errorRef, emptyMessage) => computed({
    get: () => props[propName],
    set: (value) => {
        updateFioValue(propName, value, errorRef, emptyMessage)
    },
})

const createFioPasteHandler = (propName, errorRef, emptyMessage) => (event) => {
    const pasted = (event.clipboardData || window.clipboardData).getData('text')

    if (!pasted || /^[А-Яа-яЁёA-Za-z\s-]+$/.test(pasted)) {
        return
    }

    event.preventDefault()

    const sanitizedClipboard = sanitizeFio(pasted)
    const target = event.target

    requestAnimationFrame(() => {
        const start = target.selectionStart
        const end = target.selectionEnd
        const current = target.value
        const updated = `${current.slice(0, start)}${sanitizedClipboard}${current.slice(end)}`

        updateFioValue(propName, updated, errorRef, emptyMessage)

        const newPosition = start + sanitizedClipboard.length
        requestAnimationFrame(() => {
            target.setSelectionRange(newPosition, newPosition)
        })
    })
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

const fioProxy = createFioProxy('fio', fioError, parentFioEmptyMessage)
const childFioProxy = createFioProxy('childFio', childFioError, childFioEmptyMessage)
const handleParentFioPaste = createFioPasteHandler('fio', fioError, parentFioEmptyMessage)
const handleChildFioPaste = createFioPasteHandler('childFio', childFioError, childFioEmptyMessage)

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

const promoCodeProxy = computed({
    get: () => props.promoCode,
    set: (value) => emits('update:promoCode', value ?? ''),
})

const validateFields = () => {
    let isValid = true

    const trimmedFio = (props.fio || '').trim()
    if (!trimmedFio) {
        fioError.value = parentFioEmptyMessage
        isValid = false
    } else if (fioError.value === parentFioEmptyMessage || fioError.value === 'Можно вводить только буквы') {
        fioError.value = ''
    }

    const trimmedChildFio = (props.childFio || '').trim()
    if (!trimmedChildFio) {
        childFioError.value = childFioEmptyMessage
        isValid = false
    } else {
        // Проверяем, что введено хотя бы 2 слова (Фамилия и Имя)
        const words = trimmedChildFio.split(/\s+/).filter(w => w.length > 0)
        if (words.length < 2) {
            childFioError.value = 'Укажите Фамилию и Имя ребенка'
            isValid = false
        } else {
            childFioError.value = ''
        }
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

    return isValid && !fioError.value && !childFioError.value && !phoneError.value
}

const consentWrapperClasses = computed(() => [
    'flex items-center gap-3 rounded-lg border px-3 py-2 text-sm text-gray-700 cursor-pointer select-none transition focus-within:ring-2 focus-within:ring-primary focus-within:border-primary',
    consentError.value ? 'border-red-500' : 'border-gray-300',
])

const handleSubmit = () => {
    // Если шаг в режиме только просмотра, просто отправляем событие без повторной валидации
    if (!props.showForm) {
        emits('submit')
        return
    }

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
