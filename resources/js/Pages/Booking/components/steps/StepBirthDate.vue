<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Дата рождения (необязательно)</h3>
        <BaseInput
            v-model="internalValue"
            type="date"
        />
        <div class="flex gap-2">
            <BaseButton class="flex-1 py-2" variant="primary" @click="$emit('next')">
                Продолжить
            </BaseButton>
            <BaseButton class="flex-1 py-2" variant="secondary" @click="$emit('skip')">
                Пропустить
            </BaseButton>
        </div>
        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import BaseButton from '../../../../Components/ui/BaseButton.vue'
import BaseInput from '../../../../Components/ui/BaseInput.vue'

const props = defineProps({
    modelValue: {
        type: String,
        default: null,
    },
})

const emits = defineEmits(['update:modelValue', 'next', 'skip', 'back'])

const internalValue = computed({
    get: () => props.modelValue,
    set: (value) => emits('update:modelValue', value),
})
</script>
