<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Выберите действие</h3>
        <div class="grid grid-cols-1 gap-3">
            <BaseButton class="w-full py-2" variant="secondary" @click="$emit('choose-clinic')">
                Выбрать клинику
            </BaseButton>
            <BaseButton class="w-full py-2" variant="secondary" @click="$emit('choose-doctor')">
                Выбрать доктора
            </BaseButton>
        </div>
        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import BaseButton from '../../../../Components/ui/BaseButton.vue'

defineEmits(['choose-clinic', 'choose-doctor', 'back'])
</script>
