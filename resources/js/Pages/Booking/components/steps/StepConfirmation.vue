<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Форма записи</h3>

        <div v-if="selectedSlot" class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-700 space-y-1">
            <p><span class="font-medium">Дата:</span> {{ selectedDateLabel }}</p>
            <p><span class="font-medium">Время:</span> {{ selectedSlot.time }}</p>
            <p v-if="doctor"><span class="font-medium">Доктор:</span> {{ doctor.name }}</p>
            <p v-if="clinicName"><span class="font-medium">Клиника:</span> {{ clinicName }}</p>
            <p v-if="branchName"><span class="font-medium">Филиал:</span> {{ branchName }}</p>
            <p v-if="selectedSlot.cabinet_name"><span class="font-medium">Кабинет:</span> {{ selectedSlot.cabinet_name }}</p>
        </div>

        <form class="space-y-3" @submit.prevent="$emit('submit')">
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
})

const emits = defineEmits(['update:fio', 'update:phone', 'submit', 'back'])

const fioError = ref('')
const phoneError = ref('')

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
    } else {
        fioError.value = ''
    }

    return sanitized
}

const formatPhone = (value) => {
    const digitsOnly = value.replace(/\D/g, '')

    if (!digitsOnly.length) {
        phoneError.value = ''
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
    set: (value) => emits('update:fio', sanitizeFio(value)),
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
    },
})
</script>
